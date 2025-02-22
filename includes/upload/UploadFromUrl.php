<?php

use MediaWiki\MediaWikiServices;

/**
 * Backend for uploading files from a HTTP resource.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Upload
 */

/**
 * Implements uploading from a HTTP resource.
 *
 * @ingroup Upload
 * @author Bryan Tong Minh
 * @author Michael Dale
 */
class UploadFromUrl extends UploadBase {
	protected $mUrl;

	protected $mTempPath, $mTmpHandle;

	protected static $allowedUrls = [];

	/**
	 * Checks if the user is allowed to use the upload-by-URL feature. If the
	 * user is not allowed, return the name of the user right as a string. If
	 * the user is allowed, have the parent do further permissions checking.
	 *
	 * @param User $user
	 *
	 * @return bool|string
	 */
	public static function isAllowed( $user ) {
		if ( !$user->isAllowed( 'upload_by_url' ) ) {
			return 'upload_by_url';
		}

		return parent::isAllowed( $user );
	}

	/**
	 * Checks if the upload from URL feature is enabled
	 * @return bool
	 */
	public static function isEnabled() {
		global $wgAllowCopyUploads;

		return $wgAllowCopyUploads && parent::isEnabled();
	}

	/**
	 * Checks whether the URL is for an allowed host
	 * The domains in the whitelist can include wildcard characters (*) in place
	 * of any of the domain levels, e.g. '*.flickr.com' or 'upload.*.gov.uk'.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function isAllowedHost( $url ) {
		global $wgCopyUploadsDomains;
		if ( !count( $wgCopyUploadsDomains ) ) {
			return true;
		}
		$parsedUrl = wfParseUrl( $url );
		if ( !$parsedUrl ) {
			return false;
		}
		$valid = false;
		foreach ( $wgCopyUploadsDomains as $domain ) {
			// See if the domain for the upload matches this whitelisted domain
			$whitelistedDomainPieces = explode( '.', $domain );
			$uploadDomainPieces = explode( '.', $parsedUrl['host'] );
			if ( count( $whitelistedDomainPieces ) === count( $uploadDomainPieces ) ) {
				$valid = true;
				// See if all the pieces match or not (excluding wildcards)
				foreach ( $whitelistedDomainPieces as $index => $piece ) {
					if ( $piece !== '*' && $piece !== $uploadDomainPieces[$index] ) {
						$valid = false;
					}
				}
				if ( $valid ) {
					// We found a match, so quit comparing against the list
					break;
				}
			}
			/* Non-wildcard test
			if ( $parsedUrl['host'] === $domain ) {
				$valid = true;
				break;
			}
			*/
		}

		return $valid;
	}

	/**
	 * Checks whether the URL is not allowed.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function isAllowedUrl( $url ) {
		if ( !isset( self::$allowedUrls[$url] ) ) {
			$allowed = true;
			Hooks::run( 'IsUploadAllowedFromUrl', [ $url, &$allowed ] );
			self::$allowedUrls[$url] = $allowed;
		}

		return self::$allowedUrls[$url];
	}

	/**
	 * Entry point for API upload
	 *
	 * @param string $name
	 * @param string $url
	 * @throws MWException
	 */
	public function initialize( $name, $url ) {
		$this->mUrl = $url;

		$tempPath = $this->makeTemporaryFile();
		# File size and removeTempFile will be filled in later
		$this->initializePathInfo( $name, $tempPath, 0, false );
	}

	/**
	 * Entry point for SpecialUpload
	 * @param WebRequest &$request
	 */
	public function initializeFromRequest( &$request ) {
		$desiredDestName = $request->getText( 'wpDestFile' );
		if ( !$desiredDestName ) {
			$desiredDestName = $request->getText( 'wpUploadFileURL' );
		}
		$this->initialize(
			$desiredDestName,
			trim( $request->getVal( 'wpUploadFileURL' ) )
		);
	}

	/**
	 * @param WebRequest $request
	 * @return bool
	 */
	public static function isValidRequest( $request ) {
		global $wgUser;

		$url = $request->getVal( 'wpUploadFileURL' );

		return !empty( $url )
			&& $wgUser->isAllowed( 'upload_by_url' );
	}

	/**
	 * @return string
	 */
	public function getSourceType() {
		return 'url';
	}

	/**
	 * Download the file
	 *
	 * @param array $httpOptions Array of options for MWHttpRequest.
	 *   This could be used to override the timeout on the http request.
	 * @return Status
	 */
	public function fetchFile( $httpOptions = [] ) {
		if ( !Http::isValidURI( $this->mUrl ) ) {
			return Status::newFatal( 'http-invalid-url', $this->mUrl );
		}

		if ( !self::isAllowedHost( $this->mUrl ) ) {
			return Status::newFatal( 'upload-copy-upload-invalid-domain' );
		}
		if ( !self::isAllowedUrl( $this->mUrl ) ) {
			return Status::newFatal( 'upload-copy-upload-invalid-url' );
		}
		return $this->reallyFetchFile( $httpOptions );
	}

	/**
	 * Create a new temporary file in the URL subdirectory of wfTempDir().
	 *
	 * @return string Path to the file
	 */
	protected function makeTemporaryFile() {
		$tmpFile = MediaWikiServices::getInstance()->getTempFSFileFactory()
			->newTempFSFile( 'URL', 'urlupload_' );
		$tmpFile->bind( $this );

		return $tmpFile->getPath();
	}

	/**
	 * Callback: save a chunk of the result of a HTTP request to the temporary file
	 *
	 * @param mixed $req
	 * @param string $buffer
	 * @return int Number of bytes handled
	 */
	public function saveTempFileChunk( $req, $buffer ) {
		wfDebugLog( 'fileupload', 'Received chunk of ' . strlen( $buffer ) . ' bytes' );
		$nbytes = fwrite( $this->mTmpHandle, $buffer );

		if ( $nbytes == strlen( $buffer ) ) {
			$this->mFileSize += $nbytes;
		} else {
			// Well... that's not good!
			wfDebugLog(
				'fileupload',
				'Short write ' . $nbytes . '/' . strlen( $buffer ) .
					' bytes, aborting with ' . $this->mFileSize . ' uploaded so far'
			);
			fclose( $this->mTmpHandle );
			$this->mTmpHandle = false;
		}

		return $nbytes;
	}

	/**
	 * Download the file, save it to the temporary file and update the file
	 * size and set $mRemoveTempFile to true.
	 *
	 * @param array $httpOptions Array of options for MWHttpRequest
	 * @return Status
	 */
	protected function reallyFetchFile( $httpOptions = [] ) {
		global $wgCopyUploadProxy, $wgCopyUploadTimeout;
		if ( $this->mTempPath === false ) {
			return Status::newFatal( 'tmp-create-error' );
		}

		// Note the temporary file should already be created by makeTemporaryFile()
		$this->mTmpHandle = fopen( $this->mTempPath, 'wb' );
		if ( !$this->mTmpHandle ) {
			return Status::newFatal( 'tmp-create-error' );
		}
		wfDebugLog( 'fileupload', 'Temporary file created "' . $this->mTempPath . '"' );

		$this->mRemoveTempFile = true;
		$this->mFileSize = 0;

		$options = $httpOptions + [ 'followRedirects' => true ];

		if ( $wgCopyUploadProxy !== false ) {
			$options['proxy'] = $wgCopyUploadProxy;
		}

		if ( $wgCopyUploadTimeout && !isset( $options['timeout'] ) ) {
			$options['timeout'] = $wgCopyUploadTimeout;
		}
		wfDebugLog(
			'fileupload',
			'Starting download from "' . $this->mUrl . '" ' .
				'<' . implode( ',', array_keys( array_filter( $options ) ) ) . '>'
		);
		$req = MWHttpRequest::factory( $this->mUrl, $options, __METHOD__ );
		$req->setCallback( [ $this, 'saveTempFileChunk' ] );
		$status = $req->execute();

		if ( $this->mTmpHandle ) {
			// File got written ok...
			fclose( $this->mTmpHandle );
			$this->mTmpHandle = null;
		} else {
			// We encountered a write error during the download...
			return Status::newFatal( 'tmp-write-error' );
		}

		wfDebugLog( 'fileupload', $status );
		if ( $status->isOK() ) {
			wfDebugLog( 'fileupload', 'Download by URL completed successfully.' );
		} else {
			wfDebugLog(
				'fileupload',
				'Download by URL completed with HTTP status ' . $req->getStatus()
			);
		}

		return $status;
	}
}
