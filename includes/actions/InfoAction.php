<?php
/**
 * Displays information about a page.
 *
 * Copyright © 2011 Alexandre Emsenhuber
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA
 *
 * @file
 * @ingroup Actions
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\RevisionRecord;
use Wikimedia\Rdbms\Database;

/**
 * Displays information about a page.
 *
 * @ingroup Actions
 */
class InfoAction extends FormlessAction {
	const VERSION = 1;

	/**
	 * Returns the name of the action this object responds to.
	 *
	 * @return string Lowercase name
	 */
	public function getName() {
		return 'info';
	}

	/**
	 * Whether this action can still be executed by a blocked user.
	 *
	 * @return bool
	 */
	public function requiresUnblock() {
		return false;
	}

	/**
	 * Whether this action requires the wiki not to be locked.
	 *
	 * @return bool
	 */
	public function requiresWrite() {
		return false;
	}

	/**
	 * Clear the info cache for a given Title.
	 *
	 * @since 1.22
	 * @param Title $title Title to clear cache for
	 * @param int|null $revid Revision id to clear
	 */
	public static function invalidateCache( Title $title, $revid = null ) {
		if ( !$revid ) {
			$revision = Revision::newFromTitle( $title, 0, Revision::READ_LATEST );
			$revid = $revision ? $revision->getId() : null;
		}
		if ( $revid !== null ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$key = self::getCacheKey( $cache, $title, $revid );
			$cache->delete( $key );
		}
	}

	/**
	 * Shows page information on GET request.
	 *
	 * @return string Page information that will be added to the output
	 */
	public function onView() {
		$content = '';

		// Validate revision
		$oldid = $this->page->getOldID();
		if ( $oldid ) {
			$revision = $this->page->getRevisionFetched();

			// Revision is missing
			if ( $revision === null ) {
				return $this->msg( 'missing-revision', $oldid )->parse();
			}

			// Revision is not current
			if ( !$revision->isCurrent() ) {
				return $this->msg( 'pageinfo-not-current' )->plain();
			}
		}

		// "Help" button
		$this->addHelpLink( 'Page information' );

		// Page header
		if ( !$this->msg( 'pageinfo-header' )->isDisabled() ) {
			$content .= $this->msg( 'pageinfo-header' )->parse();
		}

		// Hide "This page is a member of # hidden categories" explanation
		$content .= Html::element( 'style', [],
			'.mw-hiddenCategoriesExplanation { display: none; }' ) . "\n";

		// Hide "Templates used on this page" explanation
		$content .= Html::element( 'style', [],
			'.mw-templatesUsedExplanation { display: none; }' ) . "\n";

		// Get page information
		$pageInfo = $this->pageInfo();

		// Allow extensions to add additional information
		Hooks::run( 'InfoAction', [ $this->getContext(), &$pageInfo ] );

		// Render page information
		foreach ( $pageInfo as $header => $infoTable ) {
			// Messages:
			// pageinfo-header-basic, pageinfo-header-edits, pageinfo-header-restrictions,
			// pageinfo-header-properties, pageinfo-category-info
			$content .= $this->makeHeader(
				$this->msg( "pageinfo-${header}" )->text(),
				"mw-pageinfo-${header}"
			) . "\n";
			$table = "\n";
			$below = "";
			foreach ( $infoTable as $infoRow ) {
				if ( $infoRow[0] == "below" ) {
					$below = $infoRow[1] . "\n";
					continue;
				}
				$name = ( $infoRow[0] instanceof Message ) ? $infoRow[0]->escaped() : $infoRow[0];
				$value = ( $infoRow[1] instanceof Message ) ? $infoRow[1]->escaped() : $infoRow[1];
				$id = ( $infoRow[0] instanceof Message ) ? $infoRow[0]->getKey() : null;
				$table = $this->addRow( $table, $name, $value, $id ) . "\n";
			}
			$content = $this->addTable( $content, $table ) . "\n" . $below;
		}

		// Page footer
		if ( !$this->msg( 'pageinfo-footer' )->isDisabled() ) {
			$content .= $this->msg( 'pageinfo-footer' )->parse();
		}

		return $content;
	}

	/**
	 * Creates a header that can be added to the output.
	 *
	 * @param string $header The header text.
	 * @param string $canonicalId
	 * @return string The HTML.
	 */
	protected function makeHeader( $header, $canonicalId ) {
		$spanAttribs = [ 'class' => 'mw-headline', 'id' => Sanitizer::escapeIdForAttribute( $header ) ];
		$h2Attribs = [ 'id' => Sanitizer::escapeIdForAttribute( $canonicalId ) ];

		return Html::rawElement( 'h2', $h2Attribs, Html::element( 'span', $spanAttribs, $header ) );
	}

	/**
	 * Adds a row to a table that will be added to the content.
	 *
	 * @param string $table The table that will be added to the content
	 * @param string $name The name of the row
	 * @param string $value The value of the row
	 * @param string|null $id The ID to use for the 'tr' element
	 * @return string The table with the row added
	 */
	protected function addRow( $table, $name, $value, $id ) {
		return $table .
			Html::rawElement(
				'tr',
				$id === null ? [] : [ 'id' => 'mw-' . $id ],
				Html::rawElement( 'td', [ 'style' => 'vertical-align: top;' ], $name ) .
					Html::rawElement( 'td', [], $value )
			);
	}

	/**
	 * Adds a table to the content that will be added to the output.
	 *
	 * @param string $content The content that will be added to the output
	 * @param string $table
	 * @return string The content with the table added
	 */
	protected function addTable( $content, $table ) {
		return $content . Html::rawElement( 'table', [ 'class' => 'wikitable mw-page-info' ],
			$table );
	}

	/**
	 * Returns an array of info groups (will be rendered as tables), keyed by group ID.
	 * Group IDs are arbitrary and used so that extensions may add additional information in
	 * arbitrary positions (and as message keys for section headers for the tables, prefixed
	 * with 'pageinfo-').
	 * Each info group is a non-associative array of info items (rendered as table rows).
	 * Each info item is an array with two elements: the first describes the type of
	 * information, the second the value for the current page. Both can be strings (will be
	 * interpreted as raw HTML) or messages (will be interpreted as plain text and escaped).
	 *
	 * @return array
	 */
	protected function pageInfo() {
		$services = MediaWikiServices::getInstance();

		$user = $this->getUser();
		$lang = $this->getLanguage();
		$title = $this->getTitle();
		$id = $title->getArticleID();
		$config = $this->context->getConfig();
		$linkRenderer = $services->getLinkRenderer();

		$pageCounts = $this->pageCounts( $this->page );

		$props = PageProps::getInstance()->getAllProperties( $title );
		$pageProperties = $props[$id] ?? [];

		// Basic information
		$pageInfo = [];
		$pageInfo['header-basic'] = [];

		// Display title
		$displayTitle = $pageProperties['displaytitle'] ?? $title->getPrefixedText();

		$pageInfo['header-basic'][] = [
			$this->msg( 'pageinfo-display-title' ), $displayTitle
		];

		// Is it a redirect? If so, where to?
		$redirectTarget = $this->page->getRedirectTarget();
		if ( $redirectTarget !== null ) {
			$pageInfo['header-basic'][] = [
				$this->msg( 'pageinfo-redirectsto' ),
				$linkRenderer->makeLink( $redirectTarget ) .
				$this->msg( 'word-separator' )->escaped() .
				$this->msg( 'parentheses' )->rawParams( $linkRenderer->makeLink(
					$redirectTarget,
					$this->msg( 'pageinfo-redirectsto-info' )->text(),
					[],
					[ 'action' => 'info' ]
				) )->escaped()
			];
		}

		// Default sort key
		$sortKey = $pageProperties['defaultsort'] ?? $title->getCategorySortkey();

		$sortKey = htmlspecialchars( $sortKey );
		$pageInfo['header-basic'][] = [ $this->msg( 'pageinfo-default-sort' ), $sortKey ];

		// Page length (in bytes)
		$pageInfo['header-basic'][] = [
			$this->msg( 'pageinfo-length' ), $lang->formatNum( $title->getLength() )
		];

		// Page namespace
		$pageNamespace = $title->getNsText();
		if ( $pageNamespace ) {
			$pageInfo['header-basic'][] = [ $this->msg( 'pageinfo-namespace' ), $pageNamespace ];
		}

		// Page ID (number not localised, as it's a database ID)
		$pageInfo['header-basic'][] = [ $this->msg( 'pageinfo-article-id' ), $id ];

		// Language in which the page content is (supposed to be) written
		$pageLang = $title->getPageLanguage()->getCode();

		$permissionManager = $services->getPermissionManager();

		$pageLangHtml = $pageLang . ' - ' .
			Language::fetchLanguageName( $pageLang, $lang->getCode() );
		// Link to Special:PageLanguage with pre-filled page title if user has permissions
		if ( $config->get( 'PageLanguageUseDB' )
			&& $permissionManager->userCan( 'pagelang', $user, $title )
		) {
			$pageLangHtml .= ' ' . $this->msg( 'parentheses' )->rawParams( $linkRenderer->makeLink(
				SpecialPage::getTitleValueFor( 'PageLanguage', $title->getPrefixedText() ),
				$this->msg( 'pageinfo-language-change' )->text()
			) )->escaped();
		}

		$pageInfo['header-basic'][] = [
			$this->msg( 'pageinfo-language' )->escaped(),
			$pageLangHtml
		];

		// Content model of the page
		$modelHtml = htmlspecialchars( ContentHandler::getLocalizedName( $title->getContentModel() ) );
		// If the user can change it, add a link to Special:ChangeContentModel
		if ( $config->get( 'ContentHandlerUseDB' )
			&& $permissionManager->userCan( 'editcontentmodel', $user, $title )
		) {
			$modelHtml .= ' ' . $this->msg( 'parentheses' )->rawParams( $linkRenderer->makeLink(
				SpecialPage::getTitleValueFor( 'ChangeContentModel', $title->getPrefixedText() ),
				$this->msg( 'pageinfo-content-model-change' )->text()
			) )->escaped();
		}

		$pageInfo['header-basic'][] = [
			$this->msg( 'pageinfo-content-model' ),
			$modelHtml
		];

		if ( $title->inNamespace( NS_USER ) ) {
			$pageUser = User::newFromName( $title->getRootText() );
			if ( $pageUser && $pageUser->getId() && !$pageUser->isHidden() ) {
				$pageInfo['header-basic'][] = [
					$this->msg( 'pageinfo-user-id' ),
					$pageUser->getId()
				];
			}
		}

		// Search engine status
		$pOutput = new ParserOutput();
		if ( isset( $pageProperties['noindex'] ) ) {
			$pOutput->setIndexPolicy( 'noindex' );
		}
		if ( isset( $pageProperties['index'] ) ) {
			$pOutput->setIndexPolicy( 'index' );
		}

		// Use robot policy logic
		$policy = $this->page->getRobotPolicy( 'view', $pOutput );
		$pageInfo['header-basic'][] = [
			// Messages: pageinfo-robot-index, pageinfo-robot-noindex
			$this->msg( 'pageinfo-robot-policy' ),
			$this->msg( "pageinfo-robot-${policy['index']}" )
		];

		$unwatchedPageThreshold = $config->get( 'UnwatchedPageThreshold' );
		if (
			$services->getPermissionManager()->userHasRight( $user, 'unwatchedpages' ) ||
			( $unwatchedPageThreshold !== false &&
				$pageCounts['watchers'] >= $unwatchedPageThreshold )
		) {
			// Number of page watchers
			$pageInfo['header-basic'][] = [
				$this->msg( 'pageinfo-watchers' ),
				$lang->formatNum( $pageCounts['watchers'] )
			];
			if (
				$config->get( 'ShowUpdatedMarker' ) &&
				isset( $pageCounts['visitingWatchers'] )
			) {
				$minToDisclose = $config->get( 'UnwatchedPageSecret' );
				if ( $pageCounts['visitingWatchers'] > $minToDisclose ||
					$services->getPermissionManager()->userHasRight( $user, 'unwatchedpages' ) ) {
					$pageInfo['header-basic'][] = [
						$this->msg( 'pageinfo-visiting-watchers' ),
						$lang->formatNum( $pageCounts['visitingWatchers'] )
					];
				} else {
					$pageInfo['header-basic'][] = [
						$this->msg( 'pageinfo-visiting-watchers' ),
						$this->msg( 'pageinfo-few-visiting-watchers' )
					];
				}
			}
		} elseif ( $unwatchedPageThreshold !== false ) {
			$pageInfo['header-basic'][] = [
				$this->msg( 'pageinfo-watchers' ),
				$this->msg( 'pageinfo-few-watchers' )->numParams( $unwatchedPageThreshold )
			];
		}

		// Redirects to this page
		$whatLinksHere = SpecialPage::getTitleFor( 'Whatlinkshere', $title->getPrefixedText() );
		$pageInfo['header-basic'][] = [
			$linkRenderer->makeLink(
				$whatLinksHere,
				$this->msg( 'pageinfo-redirects-name' )->text(),
				[],
				[
					'hidelinks' => 1,
					'hidetrans' => 1,
					'hideimages' => $title->getNamespace() == NS_FILE
				]
			),
			$this->msg( 'pageinfo-redirects-value' )
				->numParams( count( $title->getRedirectsHere() ) )
		];

		// Is it counted as a content page?
		if ( $this->page->isCountable() ) {
			$pageInfo['header-basic'][] = [
				$this->msg( 'pageinfo-contentpage' ),
				$this->msg( 'pageinfo-contentpage-yes' )
			];
		}

		// Subpages of this page, if subpages are enabled for the current NS
		if ( $services->getNamespaceInfo()->hasSubpages( $title->getNamespace() ) ) {
			$prefixIndex = SpecialPage::getTitleFor(
				'Prefixindex', $title->getPrefixedText() . '/' );
			$pageInfo['header-basic'][] = [
				$linkRenderer->makeLink(
					$prefixIndex,
					$this->msg( 'pageinfo-subpages-name' )->text()
				),
				$this->msg( 'pageinfo-subpages-value' )
					->numParams(
						$pageCounts['subpages']['total'],
						$pageCounts['subpages']['redirects'],
						$pageCounts['subpages']['nonredirects'] )
			];
		}

		if ( $title->inNamespace( NS_CATEGORY ) ) {
			$category = Category::newFromTitle( $title );

			// $allCount is the total number of cat members,
			// not the count of how many members are normal pages.
			$allCount = (int)$category->getPageCount();
			$subcatCount = (int)$category->getSubcatCount();
			$fileCount = (int)$category->getFileCount();
			$pagesCount = $allCount - $subcatCount - $fileCount;

			$pageInfo['category-info'] = [
				[
					$this->msg( 'pageinfo-category-total' ),
					$lang->formatNum( $allCount )
				],
				[
					$this->msg( 'pageinfo-category-pages' ),
					$lang->formatNum( $pagesCount )
				],
				[
					$this->msg( 'pageinfo-category-subcats' ),
					$lang->formatNum( $subcatCount )
				],
				[
					$this->msg( 'pageinfo-category-files' ),
					$lang->formatNum( $fileCount )
				]
			];
		}

		// Display image SHA-1 value
		if ( $title->inNamespace( NS_FILE ) ) {
			$fileObj = $services->getRepoGroup()->findFile( $title );
			if ( $fileObj !== false ) {
				// Convert the base-36 sha1 value obtained from database to base-16
				$output = Wikimedia\base_convert( $fileObj->getSha1(), 36, 16, 40 );
				$pageInfo['header-basic'][] = [
					$this->msg( 'pageinfo-file-hash' ),
					$output
				];
			}
		}

		// Page protection
		$pageInfo['header-restrictions'] = [];

		// Is this page affected by the cascading protection of something which includes it?
		if ( $title->isCascadeProtected() ) {
			$cascadingFrom = '';
			$sources = $title->getCascadeProtectionSources()[0];

			foreach ( $sources as $sourceTitle ) {
				$cascadingFrom .= Html::rawElement(
					'li', [], $linkRenderer->makeKnownLink( $sourceTitle ) );
			}

			$cascadingFrom = Html::rawElement( 'ul', [], $cascadingFrom );
			$pageInfo['header-restrictions'][] = [
				$this->msg( 'pageinfo-protect-cascading-from' ),
				$cascadingFrom
			];
		}

		// Is out protection set to cascade to other pages?
		if ( $title->areRestrictionsCascading() ) {
			$pageInfo['header-restrictions'][] = [
				$this->msg( 'pageinfo-protect-cascading' ),
				$this->msg( 'pageinfo-protect-cascading-yes' )
			];
		}

		// Page protection
		foreach ( $title->getRestrictionTypes() as $restrictionType ) {
			$protectionLevel = implode( ', ', $title->getRestrictions( $restrictionType ) );

			if ( $protectionLevel == '' ) {
				// Allow all users
				$message = $this->msg( 'protect-default' )->escaped();
			} else {
				// Administrators only
				// Messages: protect-level-autoconfirmed, protect-level-sysop
				$message = $this->msg( "protect-level-$protectionLevel" );
				if ( $message->isDisabled() ) {
					// Require "$1" permission
					$message = $this->msg( "protect-fallback", $protectionLevel )->parse();
				} else {
					$message = $message->escaped();
				}
			}
			$expiry = $title->getRestrictionExpiry( $restrictionType );
			$formattedexpiry = $this->msg( 'parentheses',
				$lang->formatExpiry( $expiry ) )->escaped();
			$message .= $this->msg( 'word-separator' )->escaped() . $formattedexpiry;

			// Messages: restriction-edit, restriction-move, restriction-create,
			// restriction-upload
			$pageInfo['header-restrictions'][] = [
				$this->msg( "restriction-$restrictionType" ), $message
			];
		}
		$protectLog = SpecialPage::getTitleFor( 'Log' );
		$pageInfo['header-restrictions'][] = [
			'below',
			$linkRenderer->makeKnownLink(
				$protectLog,
				$this->msg( 'pageinfo-view-protect-log' )->text(),
				[],
				[ 'type' => 'protect', 'page' => $title->getPrefixedText() ]
			),
		];

		if ( !$this->page->exists() ) {
			return $pageInfo;
		}

		// Edit history
		$pageInfo['header-edits'] = [];

		$firstRev = $this->page->getOldestRevision();
		$lastRev = $this->page->getRevision();
		$batch = new LinkBatch;

		if ( $firstRev ) {
			$firstRevUser = $firstRev->getUserText( RevisionRecord::FOR_THIS_USER );
			if ( $firstRevUser !== '' ) {
				$firstRevUserTitle = Title::makeTitle( NS_USER, $firstRevUser );
				$batch->addObj( $firstRevUserTitle );
				$batch->addObj( $firstRevUserTitle->getTalkPage() );
			}
		}

		if ( $lastRev ) {
			$lastRevUser = $lastRev->getUserText( RevisionRecord::FOR_THIS_USER );
			if ( $lastRevUser !== '' ) {
				$lastRevUserTitle = Title::makeTitle( NS_USER, $lastRevUser );
				$batch->addObj( $lastRevUserTitle );
				$batch->addObj( $lastRevUserTitle->getTalkPage() );
			}
		}

		$batch->execute();

		if ( $firstRev ) {
			// Page creator
			$pageInfo['header-edits'][] = [
				$this->msg( 'pageinfo-firstuser' ),
				Linker::revUserTools( $firstRev )
			];

			// Date of page creation
			$pageInfo['header-edits'][] = [
				$this->msg( 'pageinfo-firsttime' ),
				$linkRenderer->makeKnownLink(
					$title,
					$lang->userTimeAndDate( $firstRev->getTimestamp(), $user ),
					[],
					[ 'oldid' => $firstRev->getId() ]
				)
			];
		}

		if ( $lastRev ) {
			// Latest editor
			$pageInfo['header-edits'][] = [
				$this->msg( 'pageinfo-lastuser' ),
				Linker::revUserTools( $lastRev )
			];

			// Date of latest edit
			$pageInfo['header-edits'][] = [
				$this->msg( 'pageinfo-lasttime' ),
				$linkRenderer->makeKnownLink(
					$title,
					$lang->userTimeAndDate( $this->page->getTimestamp(), $user ),
					[],
					[ 'oldid' => $this->page->getLatest() ]
				)
			];
		}

		// Total number of edits
		$pageInfo['header-edits'][] = [
			$this->msg( 'pageinfo-edits' ), $lang->formatNum( $pageCounts['edits'] )
		];

		// Total number of distinct authors
		if ( $pageCounts['authors'] > 0 ) {
			$pageInfo['header-edits'][] = [
				$this->msg( 'pageinfo-authors' ), $lang->formatNum( $pageCounts['authors'] )
			];
		}

		// Recent number of edits (within past 30 days)
		$pageInfo['header-edits'][] = [
			$this->msg( 'pageinfo-recent-edits',
				$lang->formatDuration( $config->get( 'RCMaxAge' ) ) ),
			$lang->formatNum( $pageCounts['recent_edits'] )
		];

		// Recent number of distinct authors
		$pageInfo['header-edits'][] = [
			$this->msg( 'pageinfo-recent-authors' ),
			$lang->formatNum( $pageCounts['recent_authors'] )
		];

		// Array of MagicWord objects
		$magicWords = $services->getMagicWordFactory()->getDoubleUnderscoreArray();

		// Array of magic word IDs
		$wordIDs = $magicWords->names;

		// Array of IDs => localized magic words
		$localizedWords = $services->getContentLanguage()->getMagicWords();

		$listItems = [];
		foreach ( $pageProperties as $property => $value ) {
			if ( in_array( $property, $wordIDs ) ) {
				$listItems[] = Html::element( 'li', [], $localizedWords[$property][1] );
			}
		}

		$localizedList = Html::rawElement( 'ul', [], implode( '', $listItems ) );
		$hiddenCategories = $this->page->getHiddenCategories();

		if (
			count( $listItems ) > 0 ||
			count( $hiddenCategories ) > 0 ||
			$pageCounts['transclusion']['from'] > 0 ||
			$pageCounts['transclusion']['to'] > 0
		) {
			$options = [ 'LIMIT' => $config->get( 'PageInfoTransclusionLimit' ) ];
			$transcludedTemplates = $title->getTemplateLinksFrom( $options );
			if ( $config->get( 'MiserMode' ) ) {
				$transcludedTargets = [];
			} else {
				$transcludedTargets = $title->getTemplateLinksTo( $options );
			}

			// Page properties
			$pageInfo['header-properties'] = [];

			// Magic words
			if ( count( $listItems ) > 0 ) {
				$pageInfo['header-properties'][] = [
					$this->msg( 'pageinfo-magic-words' )->numParams( count( $listItems ) ),
					$localizedList
				];
			}

			// Hidden categories
			if ( count( $hiddenCategories ) > 0 ) {
				$pageInfo['header-properties'][] = [
					$this->msg( 'pageinfo-hidden-categories' )
						->numParams( count( $hiddenCategories ) ),
					Linker::formatHiddenCategories( $hiddenCategories )
				];
			}

			// Transcluded templates
			if ( $pageCounts['transclusion']['from'] > 0 ) {
				if ( $pageCounts['transclusion']['from'] > count( $transcludedTemplates ) ) {
					$more = $this->msg( 'morenotlisted' )->escaped();
				} else {
					$more = null;
				}

				$templateListFormatter = new TemplatesOnThisPageFormatter(
					$this->getContext(),
					$linkRenderer
				);

				$pageInfo['header-properties'][] = [
					$this->msg( 'pageinfo-templates' )
						->numParams( $pageCounts['transclusion']['from'] ),
					$templateListFormatter->format( $transcludedTemplates, false, $more )
				];
			}

			if ( !$config->get( 'MiserMode' ) && $pageCounts['transclusion']['to'] > 0 ) {
				if ( $pageCounts['transclusion']['to'] > count( $transcludedTargets ) ) {
					$more = $linkRenderer->makeLink(
						$whatLinksHere,
						$this->msg( 'moredotdotdot' )->text(),
						[],
						[ 'hidelinks' => 1, 'hideredirs' => 1 ]
					);
				} else {
					$more = null;
				}

				$templateListFormatter = new TemplatesOnThisPageFormatter(
					$this->getContext(),
					$linkRenderer
				);

				$pageInfo['header-properties'][] = [
					$this->msg( 'pageinfo-transclusions' )
						->numParams( $pageCounts['transclusion']['to'] ),
					$templateListFormatter->format( $transcludedTargets, false, $more )
				];
			}
		}

		return $pageInfo;
	}

	/**
	 * Returns page counts that would be too "expensive" to retrieve by normal means.
	 *
	 * @param WikiPage|Article|Page $page
	 * @return array
	 */
	protected function pageCounts( Page $page ) {
		$fname = __METHOD__;
		$config = $this->context->getConfig();
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		return $cache->getWithSetCallback(
			self::getCacheKey( $cache, $page->getTitle(), $page->getLatest() ),
			WANObjectCache::TTL_WEEK,
			function ( $oldValue, &$ttl, &$setOpts ) use ( $page, $config, $fname, $services ) {
				global $wgActorTableSchemaMigrationStage;

				$title = $page->getTitle();
				$id = $title->getArticleID();

				$dbr = wfGetDB( DB_REPLICA );
				$dbrWatchlist = wfGetDB( DB_REPLICA, 'watchlist' );
				$setOpts += Database::getCacheSetOptions( $dbr, $dbrWatchlist );

				if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
					$tables = [ 'revision_actor_temp' ];
					$field = 'revactor_actor';
					$pageField = 'revactor_page';
					$tsField = 'revactor_timestamp';
					$joins = [];
				} else {
					$tables = [ 'revision' ];
					$field = 'rev_user_text';
					$pageField = 'rev_page';
					$tsField = 'rev_timestamp';
					$joins = [];
				}

				$watchedItemStore = $services->getWatchedItemStore();

				$result = [];
				$result['watchers'] = $watchedItemStore->countWatchers( $title );

				if ( $config->get( 'ShowUpdatedMarker' ) ) {
					$updated = wfTimestamp( TS_UNIX, $page->getTimestamp() );
					$result['visitingWatchers'] = $watchedItemStore->countVisitingWatchers(
						$title,
						$updated - $config->get( 'WatchersMaxAge' )
					);
				}

				// Total number of edits
				$edits = (int)$dbr->selectField(
					'revision',
					'COUNT(*)',
					[ 'rev_page' => $id ],
					$fname
				);
				$result['edits'] = $edits;

				// Total number of distinct authors
				if ( $config->get( 'MiserMode' ) ) {
					$result['authors'] = 0;
				} else {
					$result['authors'] = (int)$dbr->selectField(
						$tables,
						"COUNT(DISTINCT $field)",
						[ $pageField => $id ],
						$fname,
						[],
						$joins
					);
				}

				// "Recent" threshold defined by RCMaxAge setting
				$threshold = $dbr->timestamp( time() - $config->get( 'RCMaxAge' ) );

				// Recent number of edits
				$edits = (int)$dbr->selectField(
					'revision',
					'COUNT(rev_page)',
					[
						'rev_page' => $id,
						"rev_timestamp >= " . $dbr->addQuotes( $threshold )
					],
					$fname
				);
				$result['recent_edits'] = $edits;

				// Recent number of distinct authors
				$result['recent_authors'] = (int)$dbr->selectField(
					$tables,
					"COUNT(DISTINCT $field)",
					[
						$pageField => $id,
						"$tsField >= " . $dbr->addQuotes( $threshold )
					],
					$fname,
					[],
					$joins
				);

				// Subpages (if enabled)
				if ( $services->getNamespaceInfo()->hasSubpages( $title->getNamespace() ) ) {
					$conds = [ 'page_namespace' => $title->getNamespace() ];
					$conds[] = 'page_title ' .
						$dbr->buildLike( $title->getDBkey() . '/', $dbr->anyString() );

					// Subpages of this page (redirects)
					$conds['page_is_redirect'] = 1;
					$result['subpages']['redirects'] = (int)$dbr->selectField(
						'page',
						'COUNT(page_id)',
						$conds,
						$fname
					);

					// Subpages of this page (non-redirects)
					$conds['page_is_redirect'] = 0;
					$result['subpages']['nonredirects'] = (int)$dbr->selectField(
						'page',
						'COUNT(page_id)',
						$conds,
						$fname
					);

					// Subpages of this page (total)
					$result['subpages']['total'] = $result['subpages']['redirects']
						+ $result['subpages']['nonredirects'];
				}

				// Counts for the number of transclusion links (to/from)
				if ( $config->get( 'MiserMode' ) ) {
					$result['transclusion']['to'] = 0;
				} else {
					$result['transclusion']['to'] = (int)$dbr->selectField(
						'templatelinks',
						'COUNT(tl_from)',
						[
							'tl_namespace' => $title->getNamespace(),
							'tl_title' => $title->getDBkey()
						],
						$fname
					);
				}

				$result['transclusion']['from'] = (int)$dbr->selectField(
					'templatelinks',
					'COUNT(*)',
					[ 'tl_from' => $title->getArticleID() ],
					$fname
				);

				return $result;
			}
		);
	}

	/**
	 * Returns the name that goes in the "<h1>" page title.
	 *
	 * @return string
	 */
	protected function getPageTitle() {
		return $this->msg( 'pageinfo-title', $this->getTitle()->getPrefixedText() )->text();
	}

	/**
	 * Get a list of contributors of $article
	 * @return string Html
	 */
	protected function getContributors() {
		$contributors = $this->page->getContributors();
		$real_names = [];
		$user_names = [];
		$anon_ips = [];
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		# Sift for real versus user names
		/** @var User $user */
		foreach ( $contributors as $user ) {
			$page = $user->isAnon()
				? SpecialPage::getTitleFor( 'Contributions', $user->getName() )
				: $user->getUserPage();

			$hiddenPrefs = $this->context->getConfig()->get( 'HiddenPrefs' );
			if ( $user->getId() == 0 ) {
				$anon_ips[] = $linkRenderer->makeLink( $page, $user->getName() );
			} elseif ( !in_array( 'realname', $hiddenPrefs ) && $user->getRealName() ) {
				$real_names[] = $linkRenderer->makeLink( $page, $user->getRealName() );
			} else {
				$user_names[] = $linkRenderer->makeLink( $page, $user->getName() );
			}
		}

		$lang = $this->getLanguage();

		$real = $lang->listToText( $real_names );

		# "ThisSite user(s) A, B and C"
		if ( count( $user_names ) ) {
			$user = $this->msg( 'siteusers' )
				->rawParams( $lang->listToText( $user_names ) )
				->params( count( $user_names ) )->escaped();
		} else {
			$user = false;
		}

		if ( count( $anon_ips ) ) {
			$anon = $this->msg( 'anonusers' )
				->rawParams( $lang->listToText( $anon_ips ) )
				->params( count( $anon_ips ) )->escaped();
		} else {
			$anon = false;
		}

		# This is the big list, all mooshed together. We sift for blank strings
		$fulllist = [];
		foreach ( [ $real, $user, $anon ] as $s ) {
			if ( $s !== '' ) {
				array_push( $fulllist, $s );
			}
		}

		$count = count( $fulllist );

		# "Based on work by ..."
		return $count
			? $this->msg( 'othercontribs' )->rawParams(
				$lang->listToText( $fulllist ) )->params( $count )->escaped()
			: '';
	}

	/**
	 * Returns the description that goes below the "<h1>" tag.
	 *
	 * @return string
	 */
	protected function getDescription() {
		return '';
	}

	/**
	 * @param WANObjectCache $cache
	 * @param Title $title
	 * @param int $revId
	 * @return string
	 */
	protected static function getCacheKey( WANObjectCache $cache, Title $title, $revId ) {
		return $cache->makeKey( 'infoaction', md5( $title->getPrefixedText() ), $revId, self::VERSION );
	}
}
