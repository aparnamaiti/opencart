<?php
namespace Opencart\Application\Controller\Mail;
class Gdpr extends \Opencart\System\Engine\Controller {
	// admin/model/customer/gdpr/editStatus
	public function index(&$route, &$args, &$output) {
		$this->load->model('customer/gdpr');

		$gdpr_info = $this->model_customer_gdpr->getGdpr($args[0]);

		if ($gdpr_info) {
			// Choose which mail to send

			// Export plus complete
			if ($gdpr_info['action'] == 'export' && (int)$args[1] == 3) {
				$this->export($gdpr_info);
			}

			// Approve plus processing
			if ($gdpr_info['action'] == 'approve' && (int)$args[1] == 2) {
				$this->approve($gdpr_info);
			}

			// Remove plus complete
			if ($gdpr_info['action'] == 'remove' && (int)$args[1] == 3) {
				$this->remove($gdpr_info);
			}

			// Deny
			if ($args[1] == -1) {
				$this->deny($gdpr_info);
			}
		}
	}

	public function export($gdpr_info) {
		// Send the email in the correct language
		$this->load->model('localisation/language');

		$language_info = $this->model_localisation_language->getLanguage($gdpr_info['language_id']);

		if ($language_info) {
			$language_code = $language_info['code'];
		} else {
			$language_code = $this->config->get('config_language');
		}

		// Load the language for any mails using a different country code and prefixing it so it does not pollute the main data pool.
		$this->language->load($language_code, $language_code, 'mail');
		$this->language->load('mail/gdpr_export', $language_code, 'mail');

		// Add language vars to the template folder
		$results = $this->language->all();

		foreach ($results as $key => $value) {
			if (substr($key, 0, 5) == 'mail_') {
				$data[substr($key, 5)] = $value;
			}
		}

		$subject = html_entity_decode(sprintf($this->language->get('mail_text_subject'), $this->config->get('config_name')), ENT_QUOTES, 'UTF-8');

		$this->load->model('tool/image');

		if (is_file(DIR_IMAGE . html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'))) {
			$data['logo'] = $this->model_tool_image->resize(html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'), $this->config->get('theme_default_image_location_width'), $this->config->get('theme_default_image_cart_height'));
		} else {
			$data['logo'] = '';
		}

		$this->load->model('customer/customer');

		$customer_info = $this->model_customer_customer->getCustomerByEmail($gdpr_info['email']);

		if ($customer_info) {
			$data['text_hello'] = sprintf($this->language->get('mail_text_hello'), html_entity_decode($customer_info['firstname'], ENT_QUOTES, 'UTF-8'));
		} else {
			$data['text_hello'] = sprintf($this->language->get('mail_text_hello'), $this->language->get('mail_text_user'));
		}

		// Personal info
		if ($customer_info) {
			$data['customer_id'] = $customer_info['customer_id'];
			$data['firstname'] = $customer_info['firstname'];
			$data['lastname'] = $customer_info['lastname'];
			$data['email'] = $customer_info['email'];
			$data['telephone'] = $customer_info['telephone'];
		}

		// Addresses
		$data['addresses'] = [];

		if ($customer_info) {
			$results = $this->model_customer_customer->getAddresses($customer_info['customer_id']);

			foreach ($results as $result) {
				$address = [
					'firstname' => $result['firstname'],
					'lastname'  => $result['lastname'],
					'address_1' => $result['address_1'],
					'address_2' => $result['address_2'],
					'city'      => $result['city'],
					'postcode'  => $result['postcode'],
					'country'   => $result['country'],
					'zone'      => $result['zone']
				];

				if (!in_array($address, $data['addresses'])) {
					$data['addresses'][] = $address;
				}
			}
		}

		// Order Addresses
		$this->load->model('sale/order');

		$results = $this->model_sale_order->getOrders(['filter_email' => $gdpr_info['email']]);

		foreach ($results as $result) {
			$order_info = $this->model_sale_order->getOrder($result['order_id']);

			$address = [
				'firstname' => $order_info['payment_firstname'],
				'lastname'  => $order_info['payment_lastname'],
				'address_1' => $order_info['payment_address_1'],
				'address_2' => $order_info['payment_address_2'],
				'city'      => $order_info['payment_city'],
				'postcode'  => $order_info['payment_postcode'],
				'country'   => $order_info['payment_country'],
				'zone'      => $order_info['payment_zone']
			];

			if (!in_array($address, $data['addresses'])) {
				$data['addresses'][] = $address;
			}

			$address = [
				'firstname' => $order_info['shipping_firstname'],
				'lastname'  => $order_info['shipping_lastname'],
				'address_1' => $order_info['shipping_address_1'],
				'address_2' => $order_info['shipping_address_2'],
				'city'      => $order_info['shipping_city'],
				'postcode'  => $order_info['shipping_postcode'],
				'country'   => $order_info['shipping_country'],
				'zone'      => $order_info['shipping_zone']
			];

			if (!in_array($address, $data['addresses'])) {
				$data['addresses'][] = $address;
			}
		}

		// Ip's
		$data['ips'] = [];

		if ($customer_info) {
			$results = $this->model_customer_customer->getIps($customer_info['customer_id']);

			foreach ($results as $result) {
				$data['ips'][] = [
					'ip'         => $result['ip'],
					'date_added' => date($this->language->get('mail_datetime_format'), strtotime($result['date_added']))
				];
			}
		}

		$this->load->model('setting/store');

		$store_info = $this->model_setting_store->getStore($gdpr_info['store_id']);

		if ($store_info) {
			$data['store_name'] = html_entity_decode($store_info['name'], ENT_QUOTES, 'UTF-8');
			$data['store_url'] = $store_info['url'];
		} else {
			$data['store_name'] = html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8');
			$data['store_url'] = HTTPS_CATALOG;
		}

		$mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'));
		$mail->parameter = $this->config->get('config_mail_parameter');
		$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
		$mail->smtp_username = $this->config->get('config_mail_smtp_username');
		$mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
		$mail->smtp_port = $this->config->get('config_mail_smtp_port');
		$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

		$mail->setTo($gdpr_info['email']);
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender(html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
		$mail->setSubject($subject);
		$mail->setHtml($this->load->view('mail/gdpr_export', $data));
		$mail->send();
	}

	public function approve($gdpr_info) {
		// Send the email in the correct language
		$this->load->model('localisation/language');

		$language_info = $this->model_localisation_language->getLanguage($gdpr_info['language_id']);

		if ($language_info) {
			$language_code = $language_info['code'];
		} else {
			$language_code = $this->config->get('config_language');
		}

		// Load the language for any mails using a different country code and prefixing it so it does not pollute the main data pool.
		$this->language->load($language_code, $language_code, 'mail');
		$this->language->load('mail/gdpr_approve', $language_code, 'mail');

		// Add language vars to the template folder
		$results = $this->language->all();

		foreach ($results as $key => $value) {
			if (substr($key, 0, 5) == 'mail_') {
				$data[substr($key, 5)] = $value;
			}
		}

		$subject = html_entity_decode(sprintf($this->language->get('mail_text_subject'), $this->config->get('config_name')), ENT_QUOTES, 'UTF-8');

		$this->load->model('tool/image');

		if (is_file(DIR_IMAGE . html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'))) {
			$data['logo'] = $this->model_tool_image->resize(html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'), $this->config->get('theme_default_image_location_width'), $this->config->get('theme_default_image_cart_height'));
		} else {
			$data['logo'] = '';
		}

		$this->load->model('customer/customer');

		$customer_info = $this->model_customer_customer->getCustomerByEmail($gdpr_info['email']);

		if ($customer_info) {
			$data['text_hello'] = sprintf($this->language->get('mail_text_hello'), html_entity_decode($customer_info['firstname'], ENT_QUOTES, 'UTF-8'));
		} else {
			$data['text_hello'] = sprintf($this->language->get('mail_text_hello'), $this->language->get('mail_text_user'));
		}

		$data['text_gdpr'] = sprintf($this->language->get('mail_text_gdpr'), $this->config->get('config_gdpr_limit'));
		$data['text_a'] = sprintf($$this->language->get('mail_text_a'), $this->config->get('config_gdpr_limit'));

		$this->load->model('setting/store');

		$store_info = $this->model_setting_store->getStore($gdpr_info['store_id']);

		if ($store_info) {
			$data['store_name'] = html_entity_decode($store_info['name'], ENT_QUOTES, 'UTF-8');
			$data['store_url'] = $store_info['url'];
		} else {
			$data['store_name'] = html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8');
			$data['store_url'] = HTTPS_CATALOG;
		}

		$mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'));
		$mail->parameter = $this->config->get('config_mail_parameter');
		$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
		$mail->smtp_username = $this->config->get('config_mail_smtp_username');
		$mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
		$mail->smtp_port = $this->config->get('config_mail_smtp_port');
		$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

		$mail->setTo($gdpr_info['email']);
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender(html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
		$mail->setSubject($subject);
		$mail->setHtml($this->load->view('mail/gdpr_approve', $data));
		$mail->send();
	}

	public function deny($gdpr_info) {
		// Send the email in the correct language
		$this->load->model('localisation/language');

		$language_info = $this->model_localisation_language->getLanguage($gdpr_info['language_id']);

		if ($language_info) {
			$language_code = $language_info['code'];
		} else {
			$language_code = $this->config->get('config_language');
		}

		// Load the language for any mails using a different country code and prefixing it so it does not pollute the main data pool.
		$this->language->load($language_code, $language_code, 'mail');
		$this->language->load('mail/gdpr_deny', $language_code, 'mail');

		// Add language vars to the template folder
		$results = $this->language->all();

		foreach ($results as $key => $value) {
			if (substr($key, 0, 5) == 'mail_') {
				$data[substr($key, 5)] = $value;
			}
		}

		$subject = html_entity_decode(sprintf($language->get('mail_text_subject'), $this->config->get('config_name')), ENT_QUOTES, 'UTF-8');

		$this->load->model('tool/image');

		if (is_file(DIR_IMAGE . html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'))) {
			$data['logo'] = $this->model_tool_image->resize(html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'), $this->config->get('theme_default_image_location_width'), $this->config->get('theme_default_image_cart_height'));
		} else {
			$data['logo'] = '';
		}

		$data['text_request'] = $this->language->get('mail_text_' . $gdpr_info['action']);

		$this->load->model('customer/customer');

		$customer_info = $this->model_customer_customer->getCustomerByEmail($gdpr_info['email']);

		if ($customer_info) {
			$data['text_hello'] = sprintf($language->get('mail_text_hello'), html_entity_decode($customer_info['firstname'], ENT_QUOTES, 'UTF-8'));
		} else {
			$data['text_hello'] = sprintf($language->get('mail_text_hello'), $language->get('mail_text_user'));
		}

		$this->load->model('setting/store');

		$store_info = $this->model_setting_store->getStore($gdpr_info['store_id']);

		if ($store_info) {
			$data['store_name'] = html_entity_decode($store_info['name'], ENT_QUOTES, 'UTF-8');
			$data['store_url'] = $store_info['url'];
			$data['contact'] = $store_info['url'] . 'index.php?route=information/contact';
		} else {
			$data['store_name'] = html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8');
			$data['store_url'] = HTTPS_CATALOG;
			$data['contact'] = HTTPS_CATALOG . 'index.php?route=information/contact';
		}

		$mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'));
		$mail->parameter = $this->config->get('config_mail_parameter');
		$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
		$mail->smtp_username = $this->config->get('config_mail_smtp_username');
		$mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
		$mail->smtp_port = $this->config->get('config_mail_smtp_port');
		$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

		$mail->setTo($gdpr_info['email']);
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender(html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
		$mail->setSubject($subject);
		$mail->setHtml($this->load->view('mail/gdpr_deny', $data));
		$mail->send();
	}

	public function remove($gdpr_info) {
		// Send the email in the correct language
		$this->load->model('localisation/language');

		$language_info = $this->model_localisation_language->getLanguage($gdpr_info['language_id']);

		if ($language_info) {
			$language_code = $language_info['code'];
		} else {
			$language_code = $this->config->get('config_language');
		}

		// Load the language for any mails using a different country code and prefixing it so it does not pollute the main data pool.
		$this->language->load($language_code, $language_code, 'mail');
		$this->language->load('mail/gdpr_delete', $language_code, 'mail');

		// Add language vars to the template folder
		$results = $this->language->all();

		foreach ($results as $key => $value) {
			if (substr($key, 0, 5) == 'mail_') {
				$data[substr($key, 5)] = $value;
			}
		}

		$subject = html_entity_decode(sprintf($this->language->get('mail_text_subject'), $this->config->get('config_name')), ENT_QUOTES, 'UTF-8');

		$this->load->model('tool/image');

		if (is_file(DIR_IMAGE . html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'))) {
			$data['logo'] = $this->model_tool_image->resize(html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8'), $this->config->get('theme_default_image_location_width'), $this->config->get('theme_default_image_cart_height'));
		} else {
			$data['logo'] = '';
		}

		$this->load->model('customer/customer');

		$customer_info = $this->model_customer_customer->getCustomerByEmail($gdpr_info['email']);

		if ($customer_info) {
			$data['text_hello'] = sprintf($this->language->get('mail_text_hello'), html_entity_decode($customer_info['firstname'], ENT_QUOTES, 'UTF-8'));
		} else {
			$data['text_hello'] = sprintf($this->language->get('mail_text_hello'), $this->language->get('mail_text_user'));
		}

		$this->load->model('setting/store');

		$store_info = $this->model_setting_store->getStore($gdpr_info['store_id']);

		if ($store_info) {
			$data['store_name'] = html_entity_decode($store_info['name'], ENT_QUOTES, 'UTF-8');
			$data['store_url'] = $store_info['url'];
			$data['contact'] = $store_info['url'] . 'index.php?route=information/contact';
		} else {
			$data['store_name'] = html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8');
			$data['store_url'] = HTTPS_CATALOG;
			$data['contact'] = HTTPS_CATALOG . 'index.php?route=information/contact';
		}

		$mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'));
		$mail->parameter = $this->config->get('config_mail_parameter');
		$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
		$mail->smtp_username = $this->config->get('config_mail_smtp_username');
		$mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
		$mail->smtp_port = $this->config->get('config_mail_smtp_port');
		$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

		$mail->setTo($gdpr_info['email']);
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender(html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
		$mail->setSubject($subject);
		$mail->setHtml($this->load->view('mail/gdpr_delete', $data));
		$mail->send();
	}
}
