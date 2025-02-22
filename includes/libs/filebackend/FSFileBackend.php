<?php
/**
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
 * @ingroup FileBackend
 */

/**
 * File system based backend.
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
 * @ingroup FileBackend
 */
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @brief Class for a file system (FS) based file backend.
 *
 * All "containers" each map to a directory under the backend's base directory.
 * For backwards-compatibility, some container paths can be set to custom paths.
 * The domain ID will not be used in any custom paths, so this should be avoided.
 *
 * Having directories with thousands of files will diminish performance.
 * Sharding can be accomplished by using FileRepo-style hash paths.
 *
 * StatusValue messages should avoid mentioning the internal FS paths.
 * PHP warnings are assumed to be logged rather than output.
 *
 * @ingroup FileBackend
 * @since 1.19
 */
class FSFileBackend extends FileBackendStore {
	/** @var string Directory holding the container directories */
	protected $basePath;

	/** @var array Map of container names to root paths for custom container paths */
	protected $containerPaths = [];

	/** @var int File permission mode */
	protected $fileMode;
	/** @var int File permission mode */
	protected $dirMode;

	/** @var string Required OS username to own files */
	protected $fileOwner;

	/** @var bool */
	protected $isWindows;
	/** @var string OS username running this script */
	protected $currentUser;

	/** @var array */
	protected $hadWarningErrors = [];

	/**
	 * @see FileBackendStore::__construct()
	 * Additional $config params include:
	 *   - basePath       : File system directory that holds containers.
	 *   - containerPaths : Map of container names to custom file system directories.
	 *                      This should only be used for backwards-compatibility.
	 *   - fileMode       : Octal UNIX file permissions to use on files stored.
	 *   - directoryMode  : Octal UNIX file permissions to use on directories created.
	 * @param array $config
	 */
	public function __construct( array $config ) {
		parent::__construct( $config );

		$this->isWindows = ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' );
		// Remove any possible trailing slash from directories
		if ( isset( $config['basePath'] ) ) {
			$this->basePath = rtrim( $config['basePath'], '/' ); // remove trailing slash
		} else {
			$this->basePath = null; // none; containers must have explicit paths
		}

		if ( isset( $config['containerPaths'] ) ) {
			$this->containerPaths = (array)$config['containerPaths'];
			foreach ( $this->containerPaths as &$path ) {
				$path = rtrim( $path, '/' ); // remove trailing slash
			}
		}

		$this->fileMode = $config['fileMode'] ?? 0644;
		$this->dirMode = $config['directoryMode'] ?? 0777;
		if ( isset( $config['fileOwner'] ) && function_exists( 'posix_getuid' ) ) {
			$this->fileOwner = $config['fileOwner'];
			// cache this, assuming it doesn't change
			$this->currentUser = posix_getpwuid( posix_getuid() )['name'];
		}
	}

	public function getFeatures() {
		if ( $this->isWindows && version_compare( PHP_VERSION, '7.1', 'lt' ) ) {
			// PHP before 7.1 used 8-bit code page for filesystem paths on Windows;
			// See https://www.php.net/manual/en/migration71.windows-support.php
			return 0;
		} else {
			return FileBackend::ATTR_UNICODE_PATHS;
		}
	}

	protected function resolveContainerPath( $container, $relStoragePath ) {
		// Check that container has a root directory
		if ( isset( $this->containerPaths[$container] ) || isset( $this->basePath ) ) {
			// Check for sane relative paths (assume the base paths are OK)
			if ( $this->isLegalRelPath( $relStoragePath ) ) {
				return $relStoragePath;
			}
		}

		return null;
	}

	/**
	 * Sanity check a relative file system path for validity
	 *
	 * @param string $path Normalized relative path
	 * @return bool
	 */
	protected function isLegalRelPath( $path ) {
		// Check for file names longer than 255 chars
		if ( preg_match( '![^/]{256}!', $path ) ) { // ext3/NTFS
			return false;
		}
		if ( $this->isWindows ) { // NTFS
			return !preg_match( '![:*?"<>|]!', $path );
		} else {
			return true;
		}
	}

	/**
	 * Given the short (unresolved) and full (resolved) name of
	 * a container, return the file system path of the container.
	 *
	 * @param string $shortCont
	 * @param string $fullCont
	 * @return string|null
	 */
	protected function containerFSRoot( $shortCont, $fullCont ) {
		if ( isset( $this->containerPaths[$shortCont] ) ) {
			return $this->containerPaths[$shortCont];
		} elseif ( isset( $this->basePath ) ) {
			return "{$this->basePath}/{$fullCont}";
		}

		return null; // no container base path defined
	}

	/**
	 * Get the absolute file system path for a storage path
	 *
	 * @param string $storagePath Storage path
	 * @return string|null
	 */
	protected function resolveToFSPath( $storagePath ) {
		list( $fullCont, $relPath ) = $this->resolveStoragePathReal( $storagePath );
		if ( $relPath === null ) {
			return null; // invalid
		}
		list( , $shortCont, ) = FileBackend::splitStoragePath( $storagePath );
		$fsPath = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		if ( $relPath != '' ) {
			$fsPath .= "/{$relPath}";
		}

		return $fsPath;
	}

	public function isPathUsableInternal( $storagePath ) {
		$fsPath = $this->resolveToFSPath( $storagePath );
		if ( $fsPath === null ) {
			return false; // invalid
		}
		$parentDir = dirname( $fsPath );

		if ( file_exists( $fsPath ) ) {
			$ok = is_file( $fsPath ) && is_writable( $fsPath );
		} else {
			$ok = is_dir( $parentDir ) && is_writable( $parentDir );
		}

		if ( $this->fileOwner !== null && $this->currentUser !== $this->fileOwner ) {
			$ok = false;
			trigger_error( __METHOD__ . ": PHP process owner is not '{$this->fileOwner}'." );
		}

		return $ok;
	}

	protected function doCreateInternal( array $params ) {
		$status = $this->newStatus();

		$dest = $this->resolveToFSPath( $params['dst'] );
		if ( $dest === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );

			return $status;
		}

		if ( !empty( $params['async'] ) ) { // deferred
			$tempFile = $this->tmpFileFactory->newTempFSFile( 'create_', 'tmp' );
			if ( !$tempFile ) {
				$status->fatal( 'backend-fail-create', $params['dst'] );

				return $status;
			}
			$this->trapWarnings();
			$bytes = file_put_contents( $tempFile->getPath(), $params['content'] );
			$this->untrapWarnings();
			if ( $bytes === false ) {
				$status->fatal( 'backend-fail-create', $params['dst'] );

				return $status;
			}
			$cmd = implode( ' ', [
				$this->isWindows ? 'COPY /B /Y' : 'cp', // (binary, overwrite)
				escapeshellarg( $this->cleanPathSlashes( $tempFile->getPath() ) ),
				escapeshellarg( $this->cleanPathSlashes( $dest ) )
			] );
			$handler = function ( $errors, StatusValue $status, array $params, $cmd ) {
				if ( $errors !== '' && !( $this->isWindows && $errors[0] === " " ) ) {
					$status->fatal( 'backend-fail-create', $params['dst'] );
					trigger_error( "$cmd\n$errors", E_USER_WARNING ); // command output
				}
			};
			$status->value = new FSFileOpHandle( $this, $params, $handler, $cmd, $dest );
			$tempFile->bind( $status->value );
		} else { // immediate write
			$this->trapWarnings();
			$bytes = file_put_contents( $dest, $params['content'] );
			$this->untrapWarnings();
			if ( $bytes === false ) {
				$status->fatal( 'backend-fail-create', $params['dst'] );

				return $status;
			}
			$this->chmod( $dest );
		}

		return $status;
	}

	protected function doStoreInternal( array $params ) {
		$status = $this->newStatus();

		$dest = $this->resolveToFSPath( $params['dst'] );
		if ( $dest === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );

			return $status;
		}

		if ( !empty( $params['async'] ) ) { // deferred
			$cmd = implode( ' ', [
				$this->isWindows ? 'COPY /B /Y' : 'cp', // (binary, overwrite)
				escapeshellarg( $this->cleanPathSlashes( $params['src'] ) ),
				escapeshellarg( $this->cleanPathSlashes( $dest ) )
			] );
			$handler = function ( $errors, StatusValue $status, array $params, $cmd ) {
				if ( $errors !== '' && !( $this->isWindows && $errors[0] === " " ) ) {
					$status->fatal( 'backend-fail-store', $params['src'], $params['dst'] );
					trigger_error( "$cmd\n$errors", E_USER_WARNING ); // command output
				}
			};
			$status->value = new FSFileOpHandle( $this, $params, $handler, $cmd, $dest );
		} else { // immediate write
			$this->trapWarnings();
			$ok = copy( $params['src'], $dest );
			$this->untrapWarnings();
			// In some cases (at least over NFS), copy() returns true when it fails
			if ( !$ok || ( filesize( $params['src'] ) !== filesize( $dest ) ) ) {
				if ( $ok ) { // PHP bug
					unlink( $dest ); // remove broken file
					trigger_error( __METHOD__ . ": copy() failed but returned true." );
				}
				$status->fatal( 'backend-fail-store', $params['src'], $params['dst'] );

				return $status;
			}
			$this->chmod( $dest );
		}

		return $status;
	}

	protected function doCopyInternal( array $params ) {
		$status = $this->newStatus();

		$source = $this->resolveToFSPath( $params['src'] );
		if ( $source === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );

			return $status;
		}

		$dest = $this->resolveToFSPath( $params['dst'] );
		if ( $dest === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );

			return $status;
		}

		if ( !is_file( $source ) ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-copy', $params['src'] );
			}

			return $status; // do nothing; either OK or bad status
		}

		if ( !empty( $params['async'] ) ) { // deferred
			$cmd = implode( ' ', [
				$this->isWindows ? 'COPY /B /Y' : 'cp', // (binary, overwrite)
				escapeshellarg( $this->cleanPathSlashes( $source ) ),
				escapeshellarg( $this->cleanPathSlashes( $dest ) )
			] );
			$handler = function ( $errors, StatusValue $status, array $params, $cmd ) {
				if ( $errors !== '' && !( $this->isWindows && $errors[0] === " " ) ) {
					$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );
					trigger_error( "$cmd\n$errors", E_USER_WARNING ); // command output
				}
			};
			$status->value = new FSFileOpHandle( $this, $params, $handler, $cmd, $dest );
		} else { // immediate write
			$this->trapWarnings();
			$ok = ( $source === $dest ) ? true : copy( $source, $dest );
			$this->untrapWarnings();
			// In some cases (at least over NFS), copy() returns true when it fails
			if ( !$ok || ( filesize( $source ) !== filesize( $dest ) ) ) {
				if ( $ok ) { // PHP bug
					$this->trapWarnings();
					unlink( $dest ); // remove broken file
					$this->untrapWarnings();
					trigger_error( __METHOD__ . ": copy() failed but returned true." );
				}
				$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );

				return $status;
			}
			$this->chmod( $dest );
		}

		return $status;
	}

	protected function doMoveInternal( array $params ) {
		$status = $this->newStatus();

		$source = $this->resolveToFSPath( $params['src'] );
		if ( $source === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );

			return $status;
		}

		$dest = $this->resolveToFSPath( $params['dst'] );
		if ( $dest === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );

			return $status;
		}

		if ( !is_file( $source ) ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-move', $params['src'] );
			}

			return $status; // do nothing; either OK or bad status
		}

		if ( !empty( $params['async'] ) ) { // deferred
			$cmd = implode( ' ', [
				$this->isWindows ? 'MOVE /Y' : 'mv', // (overwrite)
				escapeshellarg( $this->cleanPathSlashes( $source ) ),
				escapeshellarg( $this->cleanPathSlashes( $dest ) )
			] );
			$handler = function ( $errors, StatusValue $status, array $params, $cmd ) {
				if ( $errors !== '' && !( $this->isWindows && $errors[0] === " " ) ) {
					$status->fatal( 'backend-fail-move', $params['src'], $params['dst'] );
					trigger_error( "$cmd\n$errors", E_USER_WARNING ); // command output
				}
			};
			$status->value = new FSFileOpHandle( $this, $params, $handler, $cmd );
		} else { // immediate write
			$this->trapWarnings();
			$ok = ( $source === $dest ) ? true : rename( $source, $dest );
			$this->untrapWarnings();
			clearstatcache(); // file no longer at source
			if ( !$ok ) {
				$status->fatal( 'backend-fail-move', $params['src'], $params['dst'] );

				return $status;
			}
		}

		return $status;
	}

	protected function doDeleteInternal( array $params ) {
		$status = $this->newStatus();

		$source = $this->resolveToFSPath( $params['src'] );
		if ( $source === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );

			return $status;
		}

		if ( !is_file( $source ) ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-delete', $params['src'] );
			}

			return $status; // do nothing; either OK or bad status
		}

		if ( !empty( $params['async'] ) ) { // deferred
			$cmd = implode( ' ', [
				$this->isWindows ? 'DEL' : 'unlink',
				escapeshellarg( $this->cleanPathSlashes( $source ) )
			] );
			$handler = function ( $errors, StatusValue $status, array $params, $cmd ) {
				if ( $errors !== '' && !( $this->isWindows && $errors[0] === " " ) ) {
					$status->fatal( 'backend-fail-delete', $params['src'] );
					trigger_error( "$cmd\n$errors", E_USER_WARNING ); // command output
				}
			};
			$status->value = new FSFileOpHandle( $this, $params, $handler, $cmd );
		} else { // immediate write
			$this->trapWarnings();
			$ok = unlink( $source );
			$this->untrapWarnings();
			if ( !$ok ) {
				$status->fatal( 'backend-fail-delete', $params['src'] );

				return $status;
			}
		}

		return $status;
	}

	/**
	 * @param string $fullCont
	 * @param string $dirRel
	 * @param array $params
	 * @return StatusValue
	 */
	protected function doPrepareInternal( $fullCont, $dirRel, array $params ) {
		$status = $this->newStatus();
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;
		$existed = is_dir( $dir ); // already there?
		// Create the directory and its parents as needed...
		$this->trapWarnings();
		if ( !$existed && !mkdir( $dir, $this->dirMode, true ) && !is_dir( $dir ) ) {
			$this->logger->error( __METHOD__ . ": cannot create directory $dir" );
			$status->fatal( 'directorycreateerror', $params['dir'] ); // fails on races
		} elseif ( !is_writable( $dir ) ) {
			$this->logger->error( __METHOD__ . ": directory $dir is read-only" );
			$status->fatal( 'directoryreadonlyerror', $params['dir'] );
		} elseif ( !is_readable( $dir ) ) {
			$this->logger->error( __METHOD__ . ": directory $dir is not readable" );
			$status->fatal( 'directorynotreadableerror', $params['dir'] );
		}
		$this->untrapWarnings();
		// Respect any 'noAccess' or 'noListing' flags...
		if ( is_dir( $dir ) && !$existed ) {
			$status->merge( $this->doSecureInternal( $fullCont, $dirRel, $params ) );
		}

		return $status;
	}

	protected function doSecureInternal( $fullCont, $dirRel, array $params ) {
		$status = $this->newStatus();
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;
		// Seed new directories with a blank index.html, to prevent crawling...
		if ( !empty( $params['noListing'] ) && !file_exists( "{$dir}/index.html" ) ) {
			$this->trapWarnings();
			$bytes = file_put_contents( "{$dir}/index.html", $this->indexHtmlPrivate() );
			$this->untrapWarnings();
			if ( $bytes === false ) {
				$status->fatal( 'backend-fail-create', $params['dir'] . '/index.html' );
			}
		}
		// Add a .htaccess file to the root of the container...
		if ( !empty( $params['noAccess'] ) && !file_exists( "{$contRoot}/.htaccess" ) ) {
			$this->trapWarnings();
			$bytes = file_put_contents( "{$contRoot}/.htaccess", $this->htaccessPrivate() );
			$this->untrapWarnings();
			if ( $bytes === false ) {
				$storeDir = "mwstore://{$this->name}/{$shortCont}";
				$status->fatal( 'backend-fail-create', "{$storeDir}/.htaccess" );
			}
		}

		return $status;
	}

	protected function doPublishInternal( $fullCont, $dirRel, array $params ) {
		$status = $this->newStatus();
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;
		// Unseed new directories with a blank index.html, to allow crawling...
		if ( !empty( $params['listing'] ) && is_file( "{$dir}/index.html" ) ) {
			$exists = ( file_get_contents( "{$dir}/index.html" ) === $this->indexHtmlPrivate() );
			$this->trapWarnings();
			if ( $exists && !unlink( "{$dir}/index.html" ) ) { // reverse secure()
				$status->fatal( 'backend-fail-delete', $params['dir'] . '/index.html' );
			}
			$this->untrapWarnings();
		}
		// Remove the .htaccess file from the root of the container...
		if ( !empty( $params['access'] ) && is_file( "{$contRoot}/.htaccess" ) ) {
			$exists = ( file_get_contents( "{$contRoot}/.htaccess" ) === $this->htaccessPrivate() );
			$this->trapWarnings();
			if ( $exists && !unlink( "{$contRoot}/.htaccess" ) ) { // reverse secure()
				$storeDir = "mwstore://{$this->name}/{$shortCont}";
				$status->fatal( 'backend-fail-delete', "{$storeDir}/.htaccess" );
			}
			$this->untrapWarnings();
		}

		return $status;
	}

	protected function doCleanInternal( $fullCont, $dirRel, array $params ) {
		$status = $this->newStatus();
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;
		$this->trapWarnings();
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // remove directory if empty
		}
		$this->untrapWarnings();

		return $status;
	}

	protected function doGetFileStat( array $params ) {
		$source = $this->resolveToFSPath( $params['src'] );
		if ( $source === null ) {
			return false; // invalid storage path
		}

		$this->trapWarnings(); // don't trust 'false' if there were errors
		$stat = is_file( $source ) ? stat( $source ) : false; // regular files only
		$hadError = $this->untrapWarnings();

		if ( $stat ) {
			$ct = new ConvertibleTimestamp( $stat['mtime'] );

			return [
				'mtime' => $ct->getTimestamp( TS_MW ),
				'size' => $stat['size']
			];
		} elseif ( !$hadError ) {
			return false; // file does not exist
		} else {
			return self::UNKNOWN; // failure
		}
	}

	protected function doClearCache( array $paths = null ) {
		clearstatcache(); // clear the PHP file stat cache
	}

	protected function doDirectoryExists( $fullCont, $dirRel, array $params ) {
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;

		$this->trapWarnings(); // don't trust 'false' if there were errors
		$exists = is_dir( $dir );
		$hadError = $this->untrapWarnings();

		return $hadError ? self::UNKNOWN : $exists;
	}

	/**
	 * @see FileBackendStore::getDirectoryListInternal()
	 * @param string $fullCont
	 * @param string $dirRel
	 * @param array $params
	 * @return array|FSFileBackendDirList|null
	 */
	public function getDirectoryListInternal( $fullCont, $dirRel, array $params ) {
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;
		$exists = is_dir( $dir );
		if ( !$exists ) {
			$this->logger->warning( __METHOD__ . "() given directory does not exist: '$dir'\n" );

			return []; // nothing under this dir
		} elseif ( !is_readable( $dir ) ) {
			$this->logger->warning( __METHOD__ . "() given directory is unreadable: '$dir'\n" );

			return self::UNKNOWN; // bad permissions?
		}

		return new FSFileBackendDirList( $dir, $params );
	}

	/**
	 * @see FileBackendStore::getFileListInternal()
	 * @param string $fullCont
	 * @param string $dirRel
	 * @param array $params
	 * @return array|FSFileBackendFileList|null
	 */
	public function getFileListInternal( $fullCont, $dirRel, array $params ) {
		list( , $shortCont, ) = FileBackend::splitStoragePath( $params['dir'] );
		$contRoot = $this->containerFSRoot( $shortCont, $fullCont ); // must be valid
		$dir = ( $dirRel != '' ) ? "{$contRoot}/{$dirRel}" : $contRoot;
		$exists = is_dir( $dir );
		if ( !$exists ) {
			$this->logger->warning( __METHOD__ . "() given directory does not exist: '$dir'\n" );

			return []; // nothing under this dir
		} elseif ( !is_readable( $dir ) ) {
			$this->logger->warning( __METHOD__ . "() given directory is unreadable: '$dir'\n" );

			return self::UNKNOWN; // bad permissions?
		}

		return new FSFileBackendFileList( $dir, $params );
	}

	protected function doGetLocalReferenceMulti( array $params ) {
		$fsFiles = []; // (path => FSFile)

		foreach ( $params['srcs'] as $src ) {
			$source = $this->resolveToFSPath( $src );
			if ( $source === null || !is_file( $source ) ) {
				$fsFiles[$src] = null; // invalid path or file does not exist
			} else {
				$fsFiles[$src] = new FSFile( $source );
			}
		}

		return $fsFiles;
	}

	protected function doGetLocalCopyMulti( array $params ) {
		$tmpFiles = []; // (path => TempFSFile)

		foreach ( $params['srcs'] as $src ) {
			$source = $this->resolveToFSPath( $src );
			if ( $source === null ) {
				$tmpFiles[$src] = null; // invalid path
			} else {
				// Create a new temporary file with the same extension...
				$ext = FileBackend::extensionFromPath( $src );
				$tmpFile = $this->tmpFileFactory->newTempFSFile( 'localcopy_', $ext );
				if ( !$tmpFile ) {
					$tmpFiles[$src] = null;
				} else {
					$tmpPath = $tmpFile->getPath();
					// Copy the source file over the temp file
					$this->trapWarnings();
					$ok = copy( $source, $tmpPath );
					$this->untrapWarnings();
					if ( !$ok ) {
						$tmpFiles[$src] = null;
					} else {
						$this->chmod( $tmpPath );
						$tmpFiles[$src] = $tmpFile;
					}
				}
			}
		}

		return $tmpFiles;
	}

	protected function directoriesAreVirtual() {
		return false;
	}

	/**
	 * @param FSFileOpHandle[] $fileOpHandles
	 *
	 * @return StatusValue[]
	 */
	protected function doExecuteOpHandlesInternal( array $fileOpHandles ) {
		$statuses = [];

		$pipes = [];
		foreach ( $fileOpHandles as $index => $fileOpHandle ) {
			$pipes[$index] = popen( "{$fileOpHandle->cmd} 2>&1", 'r' );
		}

		$errs = [];
		foreach ( $pipes as $index => $pipe ) {
			// Result will be empty on success in *NIX. On Windows,
			// it may be something like "        1 file(s) [copied|moved].".
			$errs[$index] = stream_get_contents( $pipe );
			fclose( $pipe );
		}

		foreach ( $fileOpHandles as $index => $fileOpHandle ) {
			$status = $this->newStatus();
			$function = $fileOpHandle->call;
			$function( $errs[$index], $status, $fileOpHandle->params, $fileOpHandle->cmd );
			$statuses[$index] = $status;
			if ( $status->isOK() && $fileOpHandle->chmodPath ) {
				$this->chmod( $fileOpHandle->chmodPath );
			}
		}

		clearstatcache(); // files changed
		return $statuses;
	}

	/**
	 * Chmod a file, suppressing the warnings
	 *
	 * @param string $path Absolute file system path
	 * @return bool Success
	 */
	protected function chmod( $path ) {
		$this->trapWarnings();
		$ok = chmod( $path, $this->fileMode );
		$this->untrapWarnings();

		return $ok;
	}

	/**
	 * Return the text of an index.html file to hide directory listings
	 *
	 * @return string
	 */
	protected function indexHtmlPrivate() {
		return '';
	}

	/**
	 * Return the text of a .htaccess file to make a directory private
	 *
	 * @return string
	 */
	protected function htaccessPrivate() {
		return "Deny from all\n";
	}

	/**
	 * Clean up directory separators for the given OS
	 *
	 * @param string $path FS path
	 * @return string
	 */
	protected function cleanPathSlashes( $path ) {
		return $this->isWindows ? strtr( $path, '/', '\\' ) : $path;
	}

	/**
	 * Listen for E_WARNING errors and track whether any happen
	 */
	protected function trapWarnings() {
		// push to stack
		$this->hadWarningErrors[] = false;
		set_error_handler( function ( $errno, $errstr ) {
			// more detailed error logging
			$this->logger->error( $errstr );
			$this->hadWarningErrors[count( $this->hadWarningErrors ) - 1] = true;

			// suppress from PHP handler
			return true;
		}, E_WARNING );
	}

	/**
	 * Stop listening for E_WARNING errors and return true if any happened
	 *
	 * @return bool
	 */
	protected function untrapWarnings() {
		// restore previous handler
		restore_error_handler();
		// pop from stack
		return array_pop( $this->hadWarningErrors );
	}
}
