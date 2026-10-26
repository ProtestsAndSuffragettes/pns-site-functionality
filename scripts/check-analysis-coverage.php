<?php
/**
 * Verify actual maintained PHP selection and analyze executable PHP snippets.
 *
 * @package PNS_Site_Functionality
 */

declare(strict_types = 1);
$pns_site_functionality_root = dirname( __DIR__ );
try {
	require_once $pns_site_functionality_root . '/vendor/autoload.php';
	$pns_site_functionality_container = ( new \PHPStan\DependencyInjection\ContainerFactory( $pns_site_functionality_root ) )->create( $pns_site_functionality_root . '/vendor/pns-site-functionality-analysis-cache/coverage', array( $pns_site_functionality_root . '/phpstan.neon' ), array() );
	if ( 8 !== (int) $pns_site_functionality_container->getParameter( 'level' ) || false !== $pns_site_functionality_container->getParameter( 'treatPhpDocTypesAsCertain' ) || 80400 !== $pns_site_functionality_container->getParameter( 'phpVersion' ) ) {
		throw new RuntimeException( 'Maintained PHP requires Level 8, unchanged PHPDoc trust and PHP 8.4.' );
	}
	// Compare extension-provided stubs and runtime bootstraps with the unchanged locked extension, rather than admitting new executable configuration.
	$pns_site_functionality_defaults = ( new \PHPStan\DependencyInjection\ContainerFactory( $pns_site_functionality_root ) )->create( $pns_site_functionality_root . '/vendor/pns-site-functionality-analysis-cache/defaults', array( $pns_site_functionality_root . '/vendor/szepeviktor/phpstan-wordpress/extension.neon' ), array() );
	foreach ( array( 'ignoreErrors', 'stubFiles', 'excludePaths', 'bootstrapFiles' ) as $pns_site_functionality_parameter ) {
		if ( $pns_site_functionality_defaults->getParameter( $pns_site_functionality_parameter ) !== $pns_site_functionality_container->getParameter( $pns_site_functionality_parameter ) ) {
			throw new RuntimeException( 'Analysis exclusions, stubs, suppressions or executable bootstraps require review.' );
		}
	}
	$pns_site_functionality_selected        = $pns_site_functionality_container->getService( 'fileFinderAnalyse' )->findFiles( $pns_site_functionality_container->getParameter( 'paths' ) )->getFiles();
	$pns_site_functionality_directory       = new RecursiveDirectoryIterator( $pns_site_functionality_root, FilesystemIterator::SKIP_DOTS );
	$pns_site_functionality_filter          = new RecursiveCallbackFilterIterator(
		$pns_site_functionality_directory,
		static function ( SplFileInfo $file ) use ( $pns_site_functionality_root ): bool {
			$relative = substr( $file->getPathname(), strlen( $pns_site_functionality_root ) + 1 );
			if ( in_array( $relative, array( '.git', '.dex', 'vendor', 'node_modules' ), true ) ) {
				return false;
			}
			if ( $file->isLink() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a standalone CLI source path diagnostic, not rendered HTML.
				throw new RuntimeException( 'Maintained source link requires review: ' . $relative );
			}
			return true;
		}
	);
	$pns_site_functionality_count           = 0;
	$pns_site_functionality_snippets        = array();
	$pns_site_functionality_xml_declaration = '~\A(?:\xEF\xBB\xBF)?<\?xml[ \t\r\n]+version[ \t\r\n]*=[ \t\r\n]*(?:"1\.[01]"|\'1\.[01]\')(?:[ \t\r\n]+encoding[ \t\r\n]*=[ \t\r\n]*(?:"[A-Za-z][A-Za-z0-9._-]*"|\'[A-Za-z][A-Za-z0-9._-]*\'))?(?:[ \t\r\n]+standalone[ \t\r\n]*=[ \t\r\n]*(?:"(?:yes|no)"|\'(?:yes|no)\'))?[ \t\r\n]*\?>~';
	// Native PHP tokens distinguish executed code from strings and XML data without executing source.
	$pns_site_functionality_classify = static function ( string $source, string $origin ) use ( $pns_site_functionality_xml_declaration ): bool {
		$inspection = preg_replace( $pns_site_functionality_xml_declaration, '', $source );
		if ( null === $inspection ) {
			throw new RuntimeException( 'Cannot classify maintained source.' );
		}
		$has_php = false;
		foreach ( token_get_all( $inspection ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( ( T_INLINE_HTML === $token[0] && str_contains( $token[1], '<?' ) ) || ( T_OPEN_TAG === $token[0] && ! str_starts_with( strtolower( $token[1] ), '<?php' ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone CLI diagnostic includes the actual maintained source origin, not HTML.
				throw new RuntimeException( 'Unsupported PHP opening tag requires review: ' . $origin );
			}
			if ( T_OPEN_TAG === $token[0] || T_OPEN_TAG_WITH_ECHO === $token[0] ) {
				$has_php = true;
			}
		}
		return $has_php;
	};
	foreach ( new RecursiveIteratorIterator( $pns_site_functionality_filter ) as $pns_site_functionality_file ) {
		if ( ! $pns_site_functionality_file->isFile() ) {
			continue;
		}
		$pns_site_functionality_path      = $pns_site_functionality_file->getPathname();
		$pns_site_functionality_relative  = substr( $pns_site_functionality_path, strlen( $pns_site_functionality_root ) + 1 );
		$pns_site_functionality_extension = strtolower( $pns_site_functionality_file->getExtension() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect local maintained source bytes without executing them or loading synthetic WordPress globals.
		$pns_site_functionality_source = file_get_contents( $pns_site_functionality_path );
		if ( false === $pns_site_functionality_source ) {
			throw new RuntimeException( 'Cannot inspect maintained source: ' . $pns_site_functionality_relative );
		}
		if ( in_array( $pns_site_functionality_extension, array( 'sh', 'yml', 'yaml' ), true ) || preg_match( '/\A#![^\n]*(?:bash|sh)\b/', $pns_site_functionality_source ) ) {
			$pns_site_functionality_remaining = preg_replace_callback(
				"/php -r '([^']*)'/s",
				static function ( array $match ) use ( &$pns_site_functionality_snippets, $pns_site_functionality_relative ): string {
					$pns_site_functionality_snippets[] = array(
						'source' => '<?php' . "\n" . $match[1],
						'origin' => $pns_site_functionality_relative,
					);
					return '';
				},
				$pns_site_functionality_source
			);
			if ( null === $pns_site_functionality_remaining ) {
				throw new RuntimeException( 'Cannot inspect inline PHP.' );
			}
			$pns_site_functionality_remaining = preg_replace_callback(
				"/<<'([A-Z_]+)'\\R(.*?)\\R\\1(?:\\R|$)/s",
				static function ( array $match ) use ( &$pns_site_functionality_snippets, $pns_site_functionality_relative, $pns_site_functionality_classify ): string {
					if ( $pns_site_functionality_classify( $match[2], $pns_site_functionality_relative ) ) {
						$pns_site_functionality_snippets[] = array(
							'source' => $match[2],
							'origin' => $pns_site_functionality_relative,
						);
						return '';
					}
					return $match[2];
				},
				$pns_site_functionality_remaining
			);
			if ( null === $pns_site_functionality_remaining ) {
				throw new RuntimeException( 'Cannot inspect PHP heredocs.' );
			}
			// The existing parser regression deliberately writes invalid PHP and must still fail composer lint:syntax.
			if ( 'tests/php-quality-contract.sh' === $pns_site_functionality_relative ) {
				$pns_site_functionality_remaining = str_replace( "printf '<?php function broken( {\\n' > \"\$fixture\"", '', $pns_site_functionality_remaining );
			}
			if ( preg_match( '/(?:^|\R|[;&|])\s*(?:run:\s*|if\s+|then\s+)?php\b[^\n]*(?:\s-[A-Za-z]*[rBRE]\b|\s--(?:run|process-begin|process-code|process-end)\b)|<\?/', $pns_site_functionality_remaining ) ) {
				throw new RuntimeException( 'Embedded PHP form requires review: ' . $pns_site_functionality_relative );
			}
			continue;
		}
		$pns_site_functionality_has_php = $pns_site_functionality_classify( $pns_site_functionality_source, $pns_site_functionality_relative );
		if ( in_array( $pns_site_functionality_extension, array( 'php', 'phtml', 'inc' ), true ) || $pns_site_functionality_has_php ) {
			if ( ! in_array( $pns_site_functionality_path, $pns_site_functionality_selected, true ) ) {
				throw new RuntimeException( 'PHPStan does not directly select maintained PHP: ' . $pns_site_functionality_relative );
			}
			++$pns_site_functionality_count;
		}
	}
	if ( 0 === $pns_site_functionality_count || array() === $pns_site_functionality_snippets ) {
		throw new RuntimeException( 'Maintained PHP or embedded PHP discovery was empty.' );
	}
	$pns_site_functionality_temporary = sys_get_temp_dir() . '/pns-site-functionality-analysis-' . bin2hex( random_bytes( 8 ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the private owned temporary analysis layout; it is outside distributed source and removed after the child exits.
	if ( ! mkdir( $pns_site_functionality_temporary, 0700 ) ) {
		throw new RuntimeException( 'Cannot create embedded analysis directory.' );
	}
	try {
		$pns_site_functionality_paths = array();
		foreach ( $pns_site_functionality_snippets as $pns_site_functionality_index => $pns_site_functionality_snippet ) {
			$pns_site_functionality_extracted = $pns_site_functionality_temporary . '/snippet-' . $pns_site_functionality_index . '.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Analyze the exact extracted executable snippet bytes; never execute their bodies or repair malformed inputs.
			if ( false === file_put_contents( $pns_site_functionality_extracted, $pns_site_functionality_snippet['source'] ) ) {
				throw new RuntimeException( 'Cannot write embedded analysis source.' );
			}
			$pns_site_functionality_paths[] = $pns_site_functionality_extracted;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emit CLI origin mapping for actual checker diagnostics, not HTML.
			echo 'Embedded PHP: ' . $pns_site_functionality_snippet['origin'] . ' -> ' . basename( $pns_site_functionality_extracted ) . PHP_EOL;
		}
		$pns_site_functionality_status = 0;
		// Each snippet has its own runtime symbol world; one process cannot satisfy another process's missing declarations.
		foreach ( $pns_site_functionality_paths as $pns_site_functionality_path ) {
			$pns_site_functionality_configuration  = "includes:\n\t- " . json_encode( $pns_site_functionality_root . '/vendor/szepeviktor/phpstan-wordpress/extension.neon', JSON_THROW_ON_ERROR ) . "\nparameters:\n\tlevel: 8\n\tphpVersion: 80400\n\ttreatPhpDocTypesAsCertain: false\n\tpaths:\n";
			$pns_site_functionality_configuration .= "\t\t- " . json_encode( $pns_site_functionality_path, JSON_THROW_ON_ERROR ) . "\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the private checker configuration containing the unchanged locked WordPress extension and exact extracted paths.
			if ( false === file_put_contents( $pns_site_functionality_temporary . '/phpstan.neon', $pns_site_functionality_configuration ) ) {
				throw new RuntimeException( 'Cannot write embedded analysis configuration.' );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the locked checker with an argument vector and propagate its real child exit status.
			$pns_site_functionality_process = proc_open( array( PHP_BINARY, $pns_site_functionality_root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $pns_site_functionality_temporary . '/phpstan.neon', '--no-progress', '--memory-limit=1G' ), array( STDIN, STDOUT, STDERR ), $pns_site_functionality_pipes );
			if ( ! is_resource( $pns_site_functionality_process ) ) {
				throw new RuntimeException( 'Cannot start embedded PHP analysis.' );
			}
			if ( 0 !== proc_close( $pns_site_functionality_process ) ) {
				$pns_site_functionality_status = 1;
			}
		}
	} finally {
		foreach ( new DirectoryIterator( $pns_site_functionality_temporary ) as $pns_site_functionality_entry ) {
			if ( ! $pns_site_functionality_entry->isDot() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only files in the owned private extraction directory after the checker child has exited.
				unlink( $pns_site_functionality_entry->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now-empty owned private extraction directory.
		rmdir( $pns_site_functionality_temporary );
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emit CLI population evidence, not HTML.
	echo 'Maintained PHP direct analysis: ' . $pns_site_functionality_count . ' files; embedded PHP: ' . count( $pns_site_functionality_snippets ) . ' snippets.' . PHP_EOL;
	exit( 0 === $pns_site_functionality_status ? 0 : 1 );
} catch ( Throwable $pns_site_functionality_error ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Emit standalone checker diagnostics to STDERR, not rendered HTML.
	fwrite( STDERR, $pns_site_functionality_error->getMessage() . PHP_EOL );
	exit( 1 );
}
