<?php

use InvalidArgumentException;
use TestHelpers\WebDavHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\History;

class WebDavHelperTest extends PHPUnit_Framework_TestCase {
	public function setUp() {
		// mocks is not used, but is required. Else it will try to
		// contact original server and will fail our tests.
		$mock = new Mock([
			new Response(200, []),
		]);

		$this->client = new Client();
		$this->history = new History();

		$this->client->getEmitter()->attach($mock);
		$this->client->getEmitter()->attach($this->history);
	}

	public function testUrlIsSanitizedByMakeDavRequestForNewerDav() {
		$response = WebDavHelper::makeDavRequest(
			'http://own.cloud///core',
			'user1',
			'pass',
			'GET',
			'folder///file.txt',
			[],
			null,
			null,
			1,
			"files",
			null,
			"basic",
			false,
			0,
			$this->client
		);

		$lastRequest = $this->history->getLastRequest();

		$this->assertEquals(
			'http://own.cloud/core/remote.php/webdav/folder/file.txt',
			$lastRequest->getUrl()
		);
		$this->assertEquals('GET', $lastRequest->getMethod());
		$this->assertEquals(
			['Basic ' . \base64_encode('user1:pass')],
			$lastRequest->getHeaders()["Authorization"]
		);
	}

	public function testUrlIsSanitizedByMakeDavRequestForOlderDavPath() {
		$response = WebDavHelper::makeDavRequest(
			'http://own.cloud///core',
			'user1',
			'pass',
			'GET',
			'folder///file.txt/',
			[],
			null,
			null,
			2,
			"files",
			null,
			"basic",
			false,
			0,
			$this->client
		);

		$lastRequest = $this->history->getLastRequest();

		$this->assertEquals(
			'http://own.cloud/core/remote.php/dav/files/user1/folder/file.txt',
			$lastRequest->getUrl()
		);
		$this->assertEquals('GET', $lastRequest->getMethod());
	}

	public function testMakeDavRequestReplacesAsteriskAndHashesOnUrls() {
		$response = WebDavHelper::makeDavRequest(
			'http://own.cloud///core',
			'user1',
			'pass',
			'GET',
			'folder/file?q=hello#newfile',
			["Destination" => 'http://own.cloud/core?q="my files"#L133'],
			null,
			null,
			2,
			"files",
			null,
			"basic",
			false,
			0,
			$this->client
		);

		$lastRequest = $this->history->getLastRequest();

		$this->assertEquals(
			'http://own.cloud/core/remote.php/dav/files/user1/folder/file%3Fq=hello%23newfile',
			$lastRequest->getUrl()
		);
		$this->assertEquals(['http://own.cloud/core%3Fq="my files"%23L133'], $lastRequest->getHeaders()["Destination"]);
	}

	public function testMakeDavRequestOnBearerAuthorization() {
		$response = WebDavHelper::makeDavRequest(
			'http://own.cloud///core',
			'user1',
			'pass',
			'GET',
			'folder',
			[],
			null,
			null,
			2,
			"files",
			null,
			"bearer",
			false,
			0,
			$this->client
		);

		$lastRequest = $this->history->getLastRequest();

		// no way to know that $user and $password is set to null, except confirming that
		// the Authorization is `Bearer`. If it would have gotten username and password,
		// it would have been `Basic`.
		$this->assertEquals(['Bearer pass'], $lastRequest->getHeaders()["Authorization"]);
	}

	/**
	 * @dataProvider withoutTrailingSlashUrlsProvider
	 *
	 * @param string $unsanitizedUrl
	 * @param string $expectedUrl
	 *
	 * @return void
	 */
	public function testSantizationOnDefault($unsanitizedUrl, $expectedUrl) {
		$sanitizedUrl = WebDavHelper::sanitizeUrl($unsanitizedUrl);
		$this->assertEquals($expectedUrl, $sanitizedUrl);
	}

	/**
	 * @dataProvider withoutTrailingSlashUrlsProvider
	 *
	 * @param string $unsanitizedUrl
	 * @param string $expectedUrl
	 *
	 * @return void
	 */
	public function testSanitizationWhenTrailingSlashIsSetToFalse($unsanitizedUrl, $expectedUrl) {
		$sanitizedUrl = WebDavHelper::sanitizeUrl($unsanitizedUrl, false);
		$this->assertEquals($expectedUrl, $sanitizedUrl);
	}

	/**
	 * @dataProvider withTrailingSlashUrlsProvider
	 *
	 * @param string $unsanitizedUrl
	 * @param string $expectedUrl
	 *
	 * @return void
	 */
	public function testSanitizationWhenTrailingSlashIsSetToTrue($unsanitizedUrl, $expectedUrl) {
		$sanitizedUrl = WebDavHelper::sanitizeUrl($unsanitizedUrl, true);
		$this->assertEquals($expectedUrl, $sanitizedUrl);
	}

	public function testGetDavPathForOlderDavVersion() {
		$davPath = WebDavHelper::getDavPath('user1', 1);
		$this->assertEquals($davPath, 'remote.php/webdav/');

		$davPath = WebDavHelper::getDavPath(null, 1);
		$this->assertEquals($davPath, 'remote.php/webdav/');

		// version 1 should be default
		$davPath = WebDavHelper::getDavPath(null);
		$this->assertEquals($davPath, 'remote.php/webdav/');
	}

	public function testGetDavPathForNewerDavPath() {
		$davPath = WebDavHelper::getDavPath('user1', 2);
		$this->assertEquals($davPath, 'remote.php/dav/files/user1/');

		$davPath = WebDavHelper::getDavPath('user1', 2, 'files');
		$this->assertEquals($davPath, 'remote.php/dav/files/user1/');

		// $davPath = WebDavHelper::getDavPath(null, 2, 'files');
		// $this->assertEquals($davPath, 'remote.php/dav/files/user1/');
	}

	public function testGetDavPathForNewerDavPathButNotForFiles() {
		$davPath = WebDavHelper::getDavPath('user1', 2, null);
		$this->assertEquals($davPath, 'remote.php/dav');

		$davPath = WebDavHelper::getDavPath('user1', 2, 'not_files');
		$this->assertEquals($davPath, 'remote.php/dav');
	}

	public function testGetDavPathForInvalidVersionsShouldThrowException() {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("DAV path version 3 is unknown");

		$davPath = WebDavHelper::getDavPath(null, 3);
	}

	public function withoutTrailingSlashUrlsProvider() {
		return [
			['http://own.cloud/', 'http://own.cloud'],
			['http://own.cloud//index.php', 'http://own.cloud/index.php'],
			['http://own.cloud//index.php//url', 'http://own.cloud/index.php/url'],
			['http://own.cloud/login//login//', 'http://own.cloud/login/login'],
			['http://own.cloud/login///login//', 'http://own.cloud/login/login'],

			// get query should not have been sanitized
			['http://own.cloud/login?redirect=//two.cloud//files', 'http://own.cloud/login?redirect=/two.cloud/files']
		];
	}

	public function withTrailingSlashUrlsProvider() {
		return [
			['http://own.cloud/', 'http://own.cloud/'],
			['http://own.cloud', 'http://own.cloud/'],
			['http://own.cloud//index.php', 'http://own.cloud/index.php/'],
			['http://own.cloud//index.php//url/', 'http://own.cloud/index.php/url/'],
			['http://own.cloud/login//login//', 'http://own.cloud/login/login/'],
			['http://own.cloud/login///login//', 'http://own.cloud/login/login/'],

			// get query should not have been sanitized
			['http://own.cloud/login?redirect=//two.cloud//files', 'http://own.cloud/login?redirect=/two.cloud/files/']
		];
	}
}
