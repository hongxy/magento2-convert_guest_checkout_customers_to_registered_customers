<?php
/**
 * Copyright © 2019 Commerce Extensions. All rights reserved.
 */

namespace CommerceExtensions\GuestToReg\Plugin;

class PaypalExpressCheckout
{
	protected $_customer;
	
	protected $_store;
	
	protected $_address;
	
	protected $_order;
	
	protected $_resource;
	
	protected $_logger;
	
	protected $_moduleData;

    public function __construct(
		\Magento\Customer\Model\Customer $customer,
		\Magento\Store\Model\Store $store,
		\Psr\Log\LoggerInterface $logger,
		\CommerceExtensions\GuestToReg\Helper\Data $moduleData,
		\Magento\Customer\Model\Address $address,
		\Magento\Sales\Model\Order $order,
		\Magento\Framework\App\ResourceConnection $resource,
		\CommerceExtensions\GuestToReg\Model\Rewrite\FrontCheckoutTypeOnepage $converter
    ) {
		$this->_customer = $customer;
		$this->_store = $store;
		$this->_logger = $logger;
		$this->_moduleData = $moduleData;
		$this->_address = $address;
		$this->_order = $order;
		$this->_resource = $resource;
		$this->_converter = $converter;
    }


	public function saveOrderFromGuest($order)
    {

		if ($this->_moduleData->moduleActive() == 1) {
		
			$entity_id = $order->getData('entity_id');
			$store_id = $this->getStoreId();
			$website_id = $this->_store->load($store_id)->getWebsiteId();
			$merged_customer_group_id = $this->_moduleData->getConfig('merged_customer_group');
			if($merged_customer_group_id == "") { $merged_customer_group_id = 1; } //General Group
			
			//DUPLICATE CUSTOMERS are appearing after import this value above is likely not found.. so we have a little check here
			if($website_id < 1) { $website_id = 1; }
			$customer = $this->_customer->setWebsiteId($website_id)->loadByEmail($order->getCustomerEmail());
			
			if (!$customer->getId()) {
		 
				/** @var $billingAddress from order */
				$billingAddress = $order->getBillingAddress();
				/** @var $shippingAddress from order */
				$shippingAddress = $order->getShippingAddress();
				// @var $fn (Customer first name) , @var $ln (Customer last name)
				$fn = $order->getCustomerFirstname();
				$ln = $order->getCustomerLastname();
			
				if(!$fn || !$ln)
				{
					foreach(array($billingAddress, $shippingAddress) as $t)
					{
						if($t->getFirstname() && $t->getLastname())
						{
							$fn = $t->getFirstname();
							$ln = $t->getLastname();
							break;
						}
					}
				}
	
				if(!$fn || !$ln)
				{
					$fn = $fn || "GUEST";
					$ln = $ln || "GUEST";
				}
				
				//oddly paypal express sometimes returning 1 for each.. reset them to names.
				if($fn=="1" || $ln=="1")
				{
					$paypalExpress = explode(" ", $order->getBillingAddress()->getData('firstname'));
					$fn = $paypalExpress[0];
					$ln = $paypalExpress[1];
				}
				//check for customer first and last name
				$customer = $this->_converter->_CreateCustomerFromGuest($order->getBillingAddress()->getData('company'), $order->getBillingAddress()->getData('city'), $order->getBillingAddress()->getData('telephone'), $order->getBillingAddress()->getData('fax'), $order->getCustomerEmail(), $order->getBillingAddress()->getData('prefix'), $fn, $middlename="", $ln, $suffix="", $taxvat="", $order->getBillingAddress()->getStreet(1), $order->getBillingAddress()->getStreet(2), $order->getBillingAddress()->getData('postcode'), $order->getBillingAddress()->getData('region'), $order->getBillingAddress()->getData('country_id'), $merged_customer_group_id, $store_id, $order->getCustomerDob(), $order->getCustomerGender());
	
				$customerId = $customer->getId();
			}
			
			$customerId = $customer->getId();
			
			$order->setCustomerId($customer->getId());
			$order->setCustomerEmail($customer->getEmail());
			$order->setCustomerFirstname($customer->getFirstname());
			$order->setCustomerLastname($customer->getLastname());
			$order->setCustomerIsGuest(false);//$order->setCustomerIsGuest('0');
			$order->setCustomerGroupId($customer->getGroupId());
			
			try {
				$order->save();
			} catch (\Exception $e) { 
				throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()), $e);
				#print_r($e->getMessage());
				#print_r($order->getData());
				#print_r($customer->getData());
			}
			
			$read = $this->_resource->getConnection('core_read');
			$write = $this->_resource->getConnection('core_write');
			$select_qry = $read->query("SELECT subscriber_status FROM ".$this->_resource->getTableName('newsletter_subscriber')." WHERE subscriber_email = '". $order->getCustomerEmail() ."'");
			$newsletter_subscriber_status = $select_qry->fetch();
			//UPDATE FOR SALES ORDER
			$write_qry = $write->update($this->_resource->getTableName('sales_order'), array('customer_id' => $customerId, 'customer_is_guest' => '0', 'customer_group_id' => $customer->getGroupId()), array("entity_id = ?" => $entity_id));
			//UPDATE FOR SALES ORDER GRID
			$write_qry = $write->update($this->_resource->getTableName('sales_order_grid'), array('customer_id' => $customerId), array("entity_id = ?" => $entity_id));
			//UPDATE FOR DOWNLOADABLE PRODUCTS
			$write_qry = $write->update($this->_resource->getTableName('downloadable_link_purchased'), array('customer_id' => $customerId), array("order_id = ?" => $entity_id));
			//UPDATE FOR NEWSLETTER
			if($newsletter_subscriber_status['subscriber_status'] !="" && $newsletter_subscriber_status['subscriber_status'] > 0)
			{
				$write_qry = $write->update($this->_resource->getTableName('newsletter_subscriber'), array('subscriber_status' => $newsletter_subscriber_status['subscriber_status']), array("subscriber_email = ?" => $order->getCustomerEmail()));
			}
		}
    }
	
	public function getStoreId()
	{
		$om = \Magento\Framework\App\ObjectManager::getInstance();
		$manager = $om->get('Magento\Store\Model\StoreManagerInterface');
		return $manager->getStore()->getStoreId();
	}
    /**
     * Retreive new incrementId
     *
     * @param int $storeId
     * @return string
     */
    public function afterPlace(\Magento\Paypal\Model\Express\Checkout $subject, $result)
    {
		#$this->_logger->addDebug("we made it to paypal via plugin");
		$order = $this->_order;
		$getorderId = $subject->getOrder();
		try {
			$orderModel = $order->load($getorderId->getId());
			#$this->_logger->addDebug("we made it to ORDERID: ". $getorderId->getId());
        	$this->saveOrderFromGuest($orderModel);
		}
		catch (\Exception $e) { 
           	throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()), $e);
		}
		
        return $result;
    }
}
