<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Daniel Calviño Sánchez <danxuliu@gmail.com>
 * @author Jan-Christoph Borchardt <hey@jancborchardt.net>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @author Maxence Lange <maxence@nextcloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Stephan Müller <mail@stephanmueller.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\FilesSharingMailNotification;


use OCP\Defaults;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Share;
use OCP\Share\IShare;
use OCP\Mail\IMailer;
use Symfony\Component\EventDispatcher\GenericEvent;


class MailManager {

	private $userManager;
	private $shareManager;
	private $config;
	private $l10nFactory;
	private $logger;
	private $urlGenerator;
	private $mailer;
	private $defaults;

	public function __construct(
		IUserManager $userManager,
		Share\IManager $shareManager,
		IConfig $config,
		IFactory $l10nFactory,
		ILogger $logger,
		IUrlGenerator $urlGenerator,
		IMailer $mailer,
		Defaults $defaults
	) {
		$this->userManager = $userManager;
		$this->shareManager = $shareManager;
		$this->config = $config;
		$this->l10nFactory = $l10nFactory;
		$this->logger = $logger;
		$this->urlGenerator = $urlGenerator;
		$this->mailer = $mailer;
		$this->defaults = $defaults;
	}

	public function handleShare(GenericEvent $event) {
		/** @var IShare $share */
		$share = $event->getSubject();

		if ($share->getShareType() === Share::SHARE_TYPE_USER) {
			$user = $this->userManager->get($share->getSharedWith());
			if ($user !== null) {
				$this->sendMailForUser($share, $user);
			}
		} else if ($share->getShareType() === Share::SHARE_TYPE_GROUP) {
			// TODO: implement
			// $this->prepareMailForGroup($share);
		}
	}

	public function sendMailForUser(IShare $share, IUser $user) {
		$mailSend = $share->getMailSend();
		if($mailSend === true) {
			if ($user !== null) {
				$emailAddress = $user->getEMailAddress();
				if ($emailAddress !== null && $emailAddress !== '') {
					$userLang = $this->config->getUserValue($share->getSharedWith(), 'core', 'lang', null);
					$l = $this->l10nFactory->get('lib', $userLang);
					try {
						$this->sendMailNotification(
							$l,
							$share->getNode()->getName(),
							$this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $share->getNode()->getId()]),
							$share->getSharedBy(),
							$emailAddress,
							$share->getExpirationDate()
						);
						$this->logger->debug('Send share notification to ' . $emailAddress . ' for share with ID ' . $share->getId(), ['app' => 'share']);
					} catch (\Exception $e) {
						$this->logger->debug('Share notification not send');
					}
				} else {
					$this->logger->debug('Share notification not send to ' . $share->getSharedWith() . ' because email address is not set.', ['app' => 'share']);
				}
			} else {
				$this->logger->debug('Share notification not send to ' . $share->getSharedWith() . ' because user could not be found.', ['app' => 'share']);
			}
		} else {
			$this->logger->debug('Share notification not send because mailsend is false.', ['app' => 'share']);
		}
	}

	public function prepareMailForGroup(IShare $share) {
		// Add share to queue
	}

	public function sendMailForGroup() {
		// TODO: Get queue
		$shares = [123, 234];
		foreach ($shares as $shareId) {
			try {
				$share = $this->shareManager->getShareById($shareId);
				$user = $this->userManager->get($share->getSharedWith());
				if ($user !== null) {
					$this->sendMailForUser($share, $user);
				}
			} catch (Share\Exceptions\ShareNotFound $e) {
			}
		}
		// TODO: remove from queue
	}

	/**
	 * @param IL10N $l Language of the recipient
	 * @param string $filename file/folder name
	 * @param string $link link to the file/folder
	 * @param string $initiator user ID of share sender
	 * @param string $shareWith email address of share receiver
	 * @param \DateTime|null $expiration
	 * @throws \Exception If mail couldn't be sent
	 */
	protected function sendMailNotification(IL10N $l,
											$filename,
											$link,
											$initiator,
											$shareWith,
											\DateTime $expiration = null) {
		$initiatorUser = $this->userManager->get($initiator);
		$initiatorDisplayName = ($initiatorUser instanceof IUser) ? $initiatorUser->getDisplayName() : $initiator;

		$message = $this->mailer->createMessage();

		$emailTemplate = $this->mailer->createEMailTemplate('files_sharing.RecipientNotification', [
			'filename' => $filename,
			'link' => $link,
			'initiator' => $initiatorDisplayName,
			'expiration' => $expiration,
			'shareWith' => $shareWith,
		]);

		$emailTemplate->setSubject($l->t('%1$s shared »%2$s« with you', array($initiatorDisplayName, $filename)));
		$emailTemplate->addHeader();
		$emailTemplate->addHeading($l->t('%1$s shared »%2$s« with you', [$initiatorDisplayName, $filename]), false);
		$text = $l->t('%1$s shared »%2$s« with you.', [$initiatorDisplayName, $filename]);

		$emailTemplate->addBodyText(
			htmlspecialchars($text . ' ' . $l->t('Click the button below to open it.')),
			$text
		);
		$emailTemplate->addBodyButton(
			$l->t('Open »%s«', [$filename]),
			$link
		);

		$message->setTo([$shareWith]);

		// The "From" contains the sharers name
		$instanceName = $this->defaults->getName();
		$senderName = $l->t(
			'%1$s via %2$s',
			[
				$initiatorDisplayName,
				$instanceName
			]
		);
		$message->setFrom([\OCP\Util::getDefaultEmailAddress($instanceName) => $senderName]);

		// The "Reply-To" is set to the sharer if an mail address is configured
		// also the default footer contains a "Do not reply" which needs to be adjusted.
		$initiatorEmail = $initiatorUser->getEMailAddress();
		if($initiatorEmail !== null) {
			$message->setReplyTo([$initiatorEmail => $initiatorDisplayName]);
			$emailTemplate->addFooter($instanceName . ($this->defaults->getSlogan() !== '' ? ' - ' . $this->defaults->getSlogan() : ''));
		} else {
			$emailTemplate->addFooter();
		}

		$message->useTemplate($emailTemplate);
		$this->mailer->send($message);
	}
}
