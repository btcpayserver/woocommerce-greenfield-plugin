<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

class GreenfieldApiAuthorization {
	public const REQUIRED_PERMISSIONS = [
		'btcpay.store.canviewinvoices',
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewstoresettings',
		'btcpay.store.canmodifyinvoices'
	];
	public const OPTIONAL_PERMISSIONS = [
		'btcpay.store.cancreatenonapprovedpullpayments',
		'btcpay.store.webhooks.canmodifywebhooks',
	];

	private $apiKey;
	private $permissions;

	public function __construct($data) {
		$this->apiKey = $data['apiKey'] ?? null;
		$this->permissions = $data['permissions'] ?? [];
	}

	public function getApiKey(): ?string
	{
		return $this->apiKey;
	}

	public function getStoreID(): string
	{
		return explode(':', $this->permissions[0])[1];
	}

	public function hasRequiredPermissions(): bool
	{
		$permissions = array_reduce($this->permissions, static function (array $carry, string $permission) {
			return array_merge($carry, [explode(':', $permission)[0]]);
		}, []);

		// Remove optional permissions so that only required ones are left.
		$permissions = array_diff($permissions, self::OPTIONAL_PERMISSIONS);

		return empty(array_merge(
			array_diff(self::REQUIRED_PERMISSIONS, $permissions),
			array_diff($permissions, self::REQUIRED_PERMISSIONS)
		));
	}

	public function hasSingleStore(): bool
	{
		if (!is_array($this->permissions) || $this->permissions === []) {
			return false;
		}

		$storeId = null;
		foreach ($this->permissions as $perms) {
			if (!is_string($perms)) {
				return false;
			}

			$exploded = explode(':', $perms);
			if (count($exploded) !== 2 || $exploded[1] === '') {
				return false;
			}

			$storeId = $storeId ?? $exploded[1];
			if ($storeId !== $exploded[1]) {
				return false;
			}
		}

		return true;
	}

	public function hasRefundsPermission(): bool {
		$permissions = array_reduce($this->permissions, static function (array $carry, string $permission) {
			return array_merge($carry, [explode(':', $permission)[0]]);
		}, []);

		return in_array('btcpay.store.cancreatenonapprovedpullpayments', $permissions, true);
	}

	public function hasWebhookPermission(): bool {
		$permissions = array_reduce($this->permissions, static function (array $carry, string $permission) {
			return array_merge($carry, [explode(':', $permission)[0]]);
		}, []);

		return in_array('btcpay.store.webhooks.canmodifywebhooks', $permissions, true);
	}
}
