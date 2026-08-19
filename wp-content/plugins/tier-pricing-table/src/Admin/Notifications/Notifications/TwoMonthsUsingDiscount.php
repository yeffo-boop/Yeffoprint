<?php

namespace TierPricingTable\Admin\Notifications\Notifications;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\TierPricingTablePlugin;
/**
 * Class Notifications
 *
 * @package TierPricingTable\Admin\Notifications
 */
class TwoMonthsUsingDiscount extends Notification {
    use ServiceContainerTrait;
    public function getMonthsNumberToUsePluginToSeeNotification() : int {
        return 2;
    }

    public function getId() : string {
        return 'two-months-using-discount';
    }

    public function getTemplate() : string {
        return 'admin/notifications/feedback-discount.php';
    }

    public function passedRequirements() : bool {
        if ( !$this->isPluginPage() ) {
            return false;
        }
        $activationDate = TierPricingTablePlugin::getPluginActivationDate();
        if ( !$activationDate ) {
            return false;
        }
        // If activation date is more than {number} months ago
        return time() - $activationDate > MONTH_IN_SECONDS * $this->getMonthsNumberToUsePluginToSeeNotification();
    }

    public function isActive() : bool {
        return $this->passedRequirements() && !$this->isSeen();
    }

}
