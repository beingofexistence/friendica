<?php
/**
 * @copyright Copyright (C) 2010-2023, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * See update_profile.php for documentation
 */

namespace Friendica\Module\Conversation;

use Friendica\App;
use Friendica\App\Mode;
use Friendica\Content\BoundariesPager;
use Friendica\Content\Conversation;
use Friendica\Content\Conversation\Entity\Timeline as TimelineEntity;
use Friendica\Content\Conversation\Factory\Timeline as TimelineFactory;
use Friendica\Content\Feature;
use Friendica\Content\Nav;
use Friendica\Content\Text\HTML;
use Friendica\Content\Widget;
use Friendica\Content\Widget\TrendingTags;
use Friendica\Core\Cache\Capability\ICanCache;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\L10n;
use Friendica\Core\PConfig\Capability\IManagePersonalConfigValues;
use Friendica\Core\Renderer;
use Friendica\Core\Session\Capability\IHandleUserSessions;
use Friendica\Network\HTTPException;
use Friendica\Database\Database;
use Friendica\Module\Response;
use Friendica\Navigation\SystemMessages;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;

class Community extends Timeline
{
	/**
	 * Type of the community page
	 * @{
	 */
	const DISABLED         = -2;
	const DISABLED_VISITOR = -1;
	const LOCAL            = 0;
	const GLOBAL           = 1;
	const LOCAL_AND_GLOBAL = 2;

	protected $pageStyle;

	/** @var TimelineFactory */
	protected $timeline;
	/** @var Conversation */
	protected $conversation;
	/** @var App\Page */
	protected $page;
	/** @var SystemMessages */
	protected $systemMessages;

	public function __construct(TimelineFactory $timeline, Conversation $conversation, App\Page $page, SystemMessages $systemMessages, Mode $mode, IHandleUserSessions $session, Database $database, IManagePersonalConfigValues $pConfig, IManageConfigValues $config, ICanCache $cache, L10n $l10n, App\BaseURL $baseUrl, App\Arguments $args, LoggerInterface $logger, Profiler $profiler, Response $response, array $server, array $parameters = [])
	{
		parent::__construct($mode, $session, $database, $pConfig, $config, $cache, $l10n, $baseUrl, $args, $logger, $profiler, $response, $server, $parameters);

		$this->timeline       = $timeline;
		$this->conversation   = $conversation;
		$this->page           = $page;
		$this->systemMessages = $systemMessages;
	}

	protected function content(array $request = []): string
	{
		$this->parseRequest($request);

		$t = Renderer::getMarkupTemplate("community.tpl");
		$o = Renderer::replaceMacros($t, [
			'$content' => '',
			'$header' => '',
			'$show_global_community_hint' => ($this->selectedTab == TimelineEntity::GLOBAL) && $this->config->get('system', 'show_global_community_hint'),
			'$global_community_hint' => $this->l10n->t("This community stream shows all public posts received by this node. They may not reflect the opinions of this node’s users.")
		]);

		if ($this->pConfig->get($this->session->getLocalUserId(), 'system', 'infinite_scroll')) {
			$tpl = Renderer::getMarkupTemplate('infinite_scroll_head.tpl');
			$o .= Renderer::replaceMacros($tpl, ['$reload_uri' => $this->args->getQueryString()]);
		}

		if (empty($request['mode']) || ($request['mode'] != 'raw')) {
			$tabs    = $this->getTabArray($this->timeline->getCommunities($this->session->isAuthenticated()), 'community');
			$tab_tpl = Renderer::getMarkupTemplate('common_tabs.tpl');
			$o .= Renderer::replaceMacros($tab_tpl, ['$tabs' => $tabs]);

			Nav::setSelected('community');

			$this->page['aside'] .= Widget::accountTypes('community/' . $this->selectedTab, $this->accountTypeString);

			if ($this->session->getLocalUserId() && $this->config->get('system', 'community_no_sharer')) {
				$this->page['aside'] .= $this->getNoSharerWidget('community');
			}

			if (Feature::isEnabled($this->session->getLocalUserId(), 'trending_tags')) {
				$this->page['aside'] .= TrendingTags::getHTML($this->selectedTab);
			}

			// We need the editor here to be able to reshare an item.
			if ($this->session->isAuthenticated()) {
				$o .= $this->conversation->statusEditor([], 0, true);
			}
		}

		$items = $this->getCommunityItems();

		if (!$this->database->isResult($items)) {
			$this->systemMessages->addNotice($this->l10n->t('No results.'));
			return $o;
		}

		$o .= $this->conversation->render($items, Conversation::MODE_COMMUNITY, false, false, 'received', $this->session->getLocalUserId());

		$pager = new BoundariesPager(
			$this->l10n,
			$this->args->getQueryString(),
			$items[0]['received'],
			$items[count($items) - 1]['received'],
			$this->itemsPerPage
		);

		if ($this->pConfig->get($this->session->getLocalUserId(), 'system', 'infinite_scroll')) {
			$o .= HTML::scrollLoader();
		} else {
			$o .= $pager->renderMinimal(count($items));
		}

		return $o;
	}

	/**
	 * Computes module parameters from the request and local configuration
	 *
	 * @throws HTTPException\BadRequestException
	 * @throws HTTPException\ForbiddenException
	 */
	protected function parseRequest($request)
	{
		parent::parseRequest($request);

		if ($this->config->get('system', 'block_public') && !$this->session->isAuthenticated()) {
			throw new HTTPException\ForbiddenException($this->l10n->t('Public access denied.'));
		}

		$this->pageStyle = $this->config->get('system', 'community_page_style');

		if ($this->pageStyle == self::DISABLED) {
			throw new HTTPException\ForbiddenException($this->l10n->t('Access denied.'));
		}

		if (!$this->selectedTab) {
			if (!empty($this->config->get('system', 'singleuser'))) {
				// On single user systems only the global page does make sense
				$this->selectedTab = TimelineEntity::GLOBAL;
			} else {
				// When only the global community is allowed, we use this as default
				$this->selectedTab = $this->pageStyle == self::GLOBAL ? TimelineEntity::GLOBAL : TimelineEntity::LOCAL;
			}
		}

		if (!$this->timeline->isCommunity($this->selectedTab)) {
			throw new HTTPException\BadRequestException($this->l10n->t('Community option not available.'));
		}

		// Check if we are allowed to display the content to visitors
		if (!$this->session->isAuthenticated()) {
			$available = $this->pageStyle == self::LOCAL_AND_GLOBAL;

			if (!$available) {
				$available = ($this->pageStyle == self::LOCAL) && ($this->selectedTab == TimelineEntity::LOCAL);
			}

			if (!$available) {
				$available = ($this->pageStyle == self::GLOBAL) && ($this->selectedTab == TimelineEntity::GLOBAL);
			}

			if (!$available) {
				throw new HTTPException\ForbiddenException($this->l10n->t('Not available.'));
			}
		}

		$this->maxId = $request['last_received'] ?? $this->maxId;
		$this->minId = $request['first_received'] ?? $this->minId;
	}
}
