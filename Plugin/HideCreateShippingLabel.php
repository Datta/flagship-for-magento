<?php

namespace Flagship\Shipping\Plugin;

use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\GetShipmentsListException;


class HideCreateShippingLabel{

    public function __construct(\Magento\Backend\App\Action\Context $context, \Flagship\Shipping\Logger\Logger $logger){

        $this->objectManager = $context->getObjectManager();
        $this->flagship = $this->objectManager->get("Flagship\Shipping\Block\Flagship");
    }

    public function afterSetLayout() : int {

        $hideCreateShippingLabel = '<script>document.addEventListener("DOMContentLoaded", function(){var getCreateShippingLabelButton = document.querySelector("[data-ui-id=\'widget-button-0\']");getCreateShippingLabelButton.style.display = "none";var getShowPackagesButton = document.querySelector("[data-ui-id=\'widget-button-2\']");getShowPackagesButton.style.display = "none";},false);</script>';
        echo $hideCreateShippingLabel;
        return 0;
    }

    public function beforeSetLayout(\Magento\Shipping\Block\Adminhtml\View $subject){

        $this->order = $subject->getShipment()->getOrder();
        $this->shipment = $subject->getShipment();
        $flagshipId = $this->order->getDataByKey("flagship_shipment_id");

        if($this->order->hasShipments() && $flagshipId === NULL ){ //magento shipping
            $trackingDetails = $this->getTrackingDetails();
            $this->updateShipmentCommentForFlagshipShipment($trackingDetails);
            return;
        }

        $shipment = $this->getFlagshipShipment($flagshipId);
        $orderId = $this->order->getId();

        if($this->isShipmentConfirmed($shipment,$orderId)){
            $this->createButtonForShipmentTracking($subject); 
            $this->updateShipmentTrackingData($shipment->getTrackingNumber(),$shipment->getLabel());
            $this->updateShipmentComment($shipment);
            return; 
        }

        //else - create buttons for confirmation
        $this->createButtonsForShipmentConfirmation($subject,$flagshipId); // shipment is still unconfirmed
        return;
    }

    protected function updateShipmentCommentForFlagshipShipment(array $trackingDetails) : bool {
        if(strcasecmp($trackingDetails["carrierCode"],'flagship') === 0 ){
            $shipment = $this->getFlagshipShipmentByTrackingNumber($trackingDetails["trackingNumber"]);
            $this->updateShipmentComment($shipment[0]);
            return TRUE;
        }
        return FALSE;
    }

    protected function getTrackingDetails() : array {
        $tracks = $this->shipment->getAllTracks();
        $details = [];
        foreach ($tracks as $track) {
            $details = [
                "trackingNumber" => $track->getTrackNumber(),
                "carrierCode" => $track->getCarrierCode()
            ];
        }
        return $details;
    }

    protected function getFlagshipShipmentByTrackingNumber($trackingNumber) : \Flagship\Shipping\Collections\GetShipmentListCollection {

        $flagship = new Flagship($this->flagship->getSettings()["token"],SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        try{
            $shipmentList = $flagship->getShipmentListRequest();
            $shipment = $shipmentList->addFilter('tracking_number',$trackingNumber)->execute();
            $this->flagship->logInfo("Retrieved shipment list from FlagShip. Response Code : ".$shipmentList->getResponseCode());
            return $shipment;
        } catch(GetShipmentListException $e){
            $this->flagship->logError($e->getMessage());
        }

    }

    protected function getShippingDescription() : string  {
        return $this->order->getShippingDescription();
    }

    protected function updateShipmentComment(\Flagship\Shipping\Objects\Shipment $shipment) : bool {

        $parentId = $this->shipment->getId();
        $shippingAmount = $this->order->getShippingAmount();

        $markup = $this->calculateMarkup($shipment->getTotal(),$shippingAmount);

        $insurance = is_null($shipment->getInsuranceValue()) ? 0 : ' CAD '.$shipment->getInsuranceValue();
        $newComment = 'FlagShip Shipment Unconfirmed';

        if(!is_null($shipment->getTrackingNumber())){

            $newComment = '<b>FlagShip Service:</b> '.$shipment->getCourierDescription().'<br><b>Tracking Number: '.$shipment->getTrackingNumber().'</b><br><b>Insurance:</b> '.$insurance.'<br><b>Customer Chose:</b> '.$this->getShippingDescription().'<br><b>You shipped with:</b> '.$shipment->getCourierDescription().'<br><b>Customer was quoted:</b> CAD '.number_format($this->order->getShippingAmount(),2).'<br><b>You Paid:</b> CAD '.number_format($shipment->getTotal(),2);
        }

        $shipment = $this->shipment;
        $comments = $shipment->getComments();
        
        if(count($comments) === 0){

            $shipment->addComment($newComment);
            $shipment->save();
            return TRUE;
        }

        foreach ($comments as $comment) {
            $comment->setComment($newComment);
            
        }

        $shipment->save();
        return TRUE;

    }

    protected function calculateMarkup(float $flagshipTotal, string $total) : float {
        $markup = (($total - $flagshipTotal)/$flagshipTotal) * 100;
        return ceil($markup);
    }

    protected function updateShipmentTrackingData(string $trackingNumber,string $label) : bool {
        $shipment = $this->shipment;

        $tracks = $shipment->getAllTracks();

        foreach ($tracks as $track) {
            $track->setTrackNumber($trackingNumber);
        }

        $shipment->setShippingLabel(file_get_contents($label));

        $shipment->save();
        return TRUE;
    }

    protected function createButtonsForShipmentConfirmation(\Magento\Shipping\Block\Adminhtml\View $subject, string $shipmentId) : bool {
        $subject->addButton(
              'flagship_shipment',
              [
                  'label' => __('Confirm FlagShip Shipment'),
                  'class' => __('action-default scalable action-secondary'),
                  'id'  => 'flagship_shipment',
                  'onclick' => sprintf("location.href = '%s' ;",$subject->getUrl('shipping/convertshipment',['shipmentId' => $shipmentId, 'order_id' => $this->order->getId() ]))
              ]
            );
        $subject->addButton(
              'flagship_shipment_update',
              [
                  'label' => __('Update FlagShip Shipment &#8618;'),
                  'class' => __('action scalable action-secondary'),
                  'id'  => 'flagship_shipment_update',
                  'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment', ['update' => 1, 'shipmentId' => $shipmentId, 'order_id' => $this->order->getId()]))
              ]
            );
        return TRUE;
    }

    protected function getFlagshipShipment($id) : \Flagship\Shipping\Objects\Shipment {
        $flagship = new Flagship($this->flagship->getSettings()["token"],SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);
        $request = $flagship->getShipmentByIdRequest($id);
        $shipment = $request->execute();
        return $shipment;
    }

    protected function isShipmentConfirmed(\Flagship\Shipping\Objects\Shipment $shipment, string $orderId) : bool {
        if(strcasecmp($shipment->getStatus(),'Prequoted')){
            $this->flagship->logInfo("FlagShip Shipment# ".$shipment->getId()." for Order# ".$orderId ." is confirmed");
            return TRUE;
        }
        return FALSE;
    }

    protected function getFlagshipShipmentId() : string {
        return $this->order->getDataByKey('flagship_shipment_id');
    }

    protected function createButtonForShipmentTracking(\Magento\Shipping\Block\Adminhtml\View $subject){
        $shipmentId =$this->getFlagshipShipmentId();
        return $subject->addButton(
              'flagship_tracking',
              [
                  'label' => __('FlagShip Shipment : '.$shipmentId),
                  'class' => __('action-default scalable action-secondary'),
                  'id'  => 'flagship_tracking',
                  'onclick' =>'popWin("'.SMARTSHIP_WEB_URL.'/shipping/'.$shipmentId.'/overview","gallery","width=1000,height=700,left=200,top=100,location=no,status=yes,scrollbars=yes,resizable=yes")'
              ]
        );
    }
}