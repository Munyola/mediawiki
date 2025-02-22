<?php
/**
 * Merge $wgExtensionMessagesFiles from various extensions to produce a
 * single array containing all message files.
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
 * @ingroup Maintenance
 */

# Start from scratch
define( 'MW_NO_EXTENSION_MESSAGES', 1 );

require_once __DIR__ . '/Maintenance.php';
$maintClass = MergeMessageFileList::class;
$mmfl = false;

/**
 * Maintenance script that merges $wgExtensionMessagesFiles from various
 * extensions to produce a single array containing all message files.
 *
 * @ingroup Maintenance
 */
class MergeMessageFileList extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->addOption(
			'list-file',
			'A file containing a list of extension setup files, one per line.',
			false,
			true
		);
		$this->addOption( 'extensions-dir', 'Path where extensions can be found.', false, true );
		$this->addOption( 'output', 'Send output to this file (omit for stdout)', false, true );
		$this->addDescription( 'Merge $wgExtensionMessagesFiles and $wgMessagesDirs from ' .
			' various extensions to produce a single file listing all message files and dirs.'
		);
	}

	public function execute() {
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.wgPrefix
		global $mmfl;
		global $wgExtensionEntryPointListFiles;

		if ( !count( $wgExtensionEntryPointListFiles )
			&& !$this->hasOption( 'list-file' )
			&& !$this->hasOption( 'extensions-dir' )
		) {
			$this->fatalError( "Either --list-file or --extensions-dir must be provided if " .
				"\$wgExtensionEntryPointListFiles is not set" );
		}

		$mmfl = [ 'setupFiles' => [] ];

		# Add setup files contained in file passed to --list-file
		if ( $this->hasOption( 'list-file' ) ) {
			$extensionPaths = $this->readFile( $this->getOption( 'list-file' ) );
			$mmfl['setupFiles'] = array_merge( $mmfl['setupFiles'], $extensionPaths );
		}

		# Now find out files in a directory
		if ( $this->hasOption( 'extensions-dir' ) ) {
			$extdir = $this->getOption( 'extensions-dir' );
			# Allow multiple directories to be passed with ":" as delimiter
			$extdirs = explode( ':', $extdir );
			foreach ( $extdirs as $extdir ) {
				$entries = scandir( $extdir );
				foreach ( $entries as $extname ) {
					if ( $extname == '.' || $extname == '..' || !is_dir( "$extdir/$extname" ) ) {
						continue;
					}
					$possibilities = [
						"$extdir/$extname/extension.json",
						"$extdir/$extname/skin.json",
						"$extdir/$extname/$extname.php"
					];
					$found = false;
					foreach ( $possibilities as $extfile ) {
						if ( file_exists( $extfile ) ) {
							$mmfl['setupFiles'][] = $extfile;
							$found = true;
							break;
						}
					}

					if ( !$found ) {
						$this->error( "Extension {$extname} in {$extdir} lacks expected entry point: " .
							"extension.json, skin.json, or {$extname}.php." );
					}
				}
			}
		}

		# Add setup files defined via configuration
		foreach ( $wgExtensionEntryPointListFiles as $points ) {
			$extensionPaths = $this->readFile( $points );
			$mmfl['setupFiles'] = array_merge( $mmfl['setupFiles'], $extensionPaths );
		}

		if ( $this->hasOption( 'output' ) ) {
			$mmfl['output'] = $this->getOption( 'output' );
		}
		if ( $this->hasOption( 'quiet' ) ) {
			$mmfl['quiet'] = true;
		}
	}

	/**
	 * @param string $fileName
	 * @return array List of absolute extension paths
	 */
	private function readFile( $fileName ) {
		global $IP;

		$files = [];
		$fileLines = file( $fileName );
		if ( $fileLines === false ) {
			$this->hasError = true;
			$this->error( "Unable to open list file $fileName." );

			return $files;
		}
		# Strip comments, discard empty lines, and trim leading and trailing
		# whitespace. Comments start with '#' and extend to the end of the line.
		foreach ( $fileLines as $extension ) {
			$extension = trim( preg_replace( '/#.*/', '', $extension ) );
			if ( $extension !== '' ) {
				# Paths may use the string $IP to be substituted by the actual value
				$extension = str_replace( '$IP', $IP, $extension );
				if ( file_exists( $extension ) ) {
					$files[] = $extension;
				} else {
					$this->hasError = true;
					$this->error( "Extension {$extension} doesn't exist" );
				}
			}
		}

		return $files;
	}
}

require_once RUN_MAINTENANCE_IF_MAIN;

$queue = [];
'@phan-var string[][] $mmfl';
foreach ( $mmfl['setupFiles'] as $fileName ) {
	if ( strval( $fileName ) === '' ) {
		continue;
	}
	if ( empty( $mmfl['quiet'] ) ) {
		fwrite( STDERR, "Loading data from $fileName\n" );
	}
	// Using extension.json or skin.json
	if ( substr( $fileName, -strlen( '.json' ) ) === '.json' ) {
		$queue[$fileName] = 1;
	} else {
		require_once $fileName;
	}
}

if ( $queue ) {
	$registry = new ExtensionRegistry();
	$data = $registry->readFromQueue( $queue );
	foreach ( [ 'wgExtensionMessagesFiles', 'wgMessagesDirs' ] as $var ) {
		if ( isset( $data['globals'][$var] ) ) {
			$GLOBALS[$var] = array_merge( $data['globals'][$var], $GLOBALS[$var] );
		}
	}
}

fwrite( STDERR, "\n" );
$s =
	"<?php\n" .
	"## This file is generated by mergeMessageFileList.php. Do not edit it directly.\n\n" .
	"if ( defined( 'MW_NO_EXTENSION_MESSAGES' ) ) return;\n\n" .
	'$wgExtensionMessagesFiles = ' . var_export( $wgExtensionMessagesFiles, true ) . ";\n\n" .
	'$wgMessagesDirs = ' . var_export( $wgMessagesDirs, true ) . ";\n\n";

$dirs = [
	$IP,
	dirname( __DIR__ ),
	realpath( $IP )
];

foreach ( $dirs as $dir ) {
	$s = preg_replace( "/'" . preg_quote( $dir, '/' ) . "([^']*)'/", '"$IP\1"', $s );
}

if ( isset( $mmfl['output'] ) ) {
	file_put_contents( $mmfl['output'], $s );
} else {
	echo $s;
}
