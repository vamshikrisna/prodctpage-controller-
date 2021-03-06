<?php namespace Magento\Catalog\Controller\Adminhtml\Product;

use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface; use Magento\Backend\App\Action; use Magento\Catalog\Api\Data\ProductInterface; use Magento\Catalog\Controller\Adminhtml\Product; use Magento\Store\Model\StoreManagerInterface; use Magento\Framework\App\Request\DataPersistorInterface;

class SaveProduct extends \Magento\Catalog\Controller\Adminhtml\Product implements HttpPostActionInterface { protected $initializationHelper;

 protected $productCopier;
 
 protected $productTypeManager;
 
 protected $categoryLinkManagement;
 
 protected $productRepository;
 
 protected $dataPersistor;
 
 private $storeManager;
 
 private $escaper;
 
 private $logger;
 
 public function __construct(
     \Magento\Backend\App\Action\Context $context,
     Product\Builder $productBuilder,
     Initialization\Helper $initializationHelper,
     \Magento\Catalog\Model\Product\Copier $productCopier,
     \Magento\Catalog\Model\Product\TypeTransitionManager $productTypeManager,
     \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
     \Magento\Framework\Escaper $escaper = null,
     \Psr\Log\LoggerInterface $logger = null
 ) {
     $this->initializationHelper = $initializationHelper;
     $this->productCopier = $productCopier;
     $this->productTypeManager = $productTypeManager;
     $this->productRepository = $productRepository;
     parent::__construct($context, $productBuilder);
     $this->escaper = $escaper ?? $this->_objectManager->get(\Magento\Framework\Escaper::class);
     $this->logger = $logger ?? $this->_objectManager->get(\Psr\Log\LoggerInterface::class);
 }
 
 public function execute()
 {
     $storeId = $this->getRequest()->getParam('store', 0);
     $store = $this->getStoreManager()->getStore($storeId);
     $this->getStoreManager()->setCurrentStore($store->getCode());
     $redirectBack = $this->getRequest()->getParam('back', false);
     $productId = $this->getRequest()->getParam('id');
     $resultRedirect = $this->resultRedirectFactory->create();
     $data = $this->getRequest()->getPostValue();
     $productAttributeSetId = $this->getRequest()->getParam('set');
     $productTypeId = $this->getRequest()->getParam('type');
     if ($data) {
         try {
             $product = $this->initializationHelper->initialize(
                 $this->productBuilder->build($this->getRequest())
             );
             $this->productTypeManager->processProduct($product);
             if (isset($data['product'][$product->getIdFieldName()])) {
                 throw new \Magento\Framework\Exception\LocalizedException(
                     __('The product was unable to be saved. Please try again.')
                 );
             }
 
             $originalSku = $product->getSku();
             $canSaveCustomOptions = $product->getCanSaveCustomOptions();
             $product->save();
             $this->handleImageRemoveError($data, $product->getId());
             $this->getCategoryLinkManagement()->assignProductToCategories(
                 $product->getSku(),
                 $product->getCategoryIds()
             );
             $productId = $product->getEntityId();
             $productAttributeSetId = $product->getAttributeSetId();
             $productTypeId = $product->getTypeId();
             $extendedData = $data;
             $extendedData['can_save_custom_options'] = $canSaveCustomOptions;
             $this->copyToStores($extendedData, $productId);
             $this->messageManager->addSuccessMessage(__('You saved the product.'));
             $this->getDataPersistor()->clear('catalog_product');
             if ($product->getSku() != $originalSku) {
                 $this->messageManager->addNoticeMessage(
                     __(
                         'SKU for product %1 has been changed to %2.',
                         $this->escaper->escapeHtml($product->getName()),
                         $this->escaper->escapeHtml($product->getSku())
                     )
                 );
             }
             $this->_eventManager->dispatch(
                 'controller_action_catalog_product_save_entity_after',
                 ['controller' => $this, 'product' => $product]
             );
 
             if ($redirectBack === 'duplicate') {
                 $product->unsetData('quantity_and_stock_status');
                 $newProduct = $this->productCopier->copy($product);
                 $this->messageManager->addSuccessMessage(__('You duplicated the product.'));
             }
         } catch (\Magento\Framework\Exception\LocalizedException $e) {
             $this->logger->critical($e);
             $this->messageManager->addExceptionMessage($e);
             $data = isset($product) ? $this->persistMediaData($product, $data) : $data;
             $this->getDataPersistor()->set('catalog_product', $data);
             $redirectBack = $productId ? true : 'new';
         } catch (\Exception $e) {
             $this->logger->critical($e);
             $this->messageManager->addErrorMessage($e->getMessage());
             $data = isset($product) ? $this->persistMediaData($product, $data) : $data;
             $this->getDataPersistor()->set('catalog_product', $data);
             $redirectBack = $productId ? true : 'new';
         }
     } else {
         $resultRedirect->setPath('catalog/*/', ['store' => $storeId]);
         $this->messageManager->addErrorMessage('No data to save');
         return $resultRedirect;
     }
 
     if ($redirectBack === 'new') {
         $resultRedirect->setPath(
             'catalog/*/new',
             ['set' => $productAttributeSetId, 'type' => $productTypeId]
         );
     } elseif ($redirectBack === 'duplicate' && isset($newProduct)) {
         $resultRedirect->setPath(
             'catalog/*/edit',
             ['id' => $newProduct->getEntityId(), 'back' => null, '_current' => true]
         );
     } elseif ($redirectBack) {
         $resultRedirect->setPath(
             'catalog/*/edit',
             ['id' => $productId, '_current' => true, 'set' => $productAttributeSetId]
         );
     } else {
         $resultRedirect->setPath('catalog/*/', ['store' => $storeId]);
     }
     return $resultRedirect;
 }
 
 private function handleImageRemoveError($postData, $productId)
 {
     if (isset($postData['product']['media_gallery']['images'])) {
         $removedImagesAmount = 0;
         foreach ($postData['product']['media_gallery']['images'] as $image) {
             if (!empty($image['removed'])) {
                 $removedImagesAmount++;
             }
         }
         if ($removedImagesAmount) {
             $expectedImagesAmount = count($postData['product']['media_gallery']['images']) - $removedImagesAmount;
             $product = $this->productRepository->getById($productId);
             $images = $product->getMediaGallery('images');
             if (is_array($images) && $expectedImagesAmount != count($images)) {
                 $this->messageManager->addNoticeMessage(
                     __('The image cannot be removed as it has been assigned to the other image role')
                 );
             }
         }
     }
 }
 
 protected function copyToStores($data, $productId)
 {
     if (!empty($data['product']['copy_to_stores'])) {
         foreach ($data['product']['copy_to_stores'] as $websiteId => $group) {
             if (isset($data['product']['website_ids'][$websiteId])
                 && (bool)$data['product']['website_ids'][$websiteId]) {
                 foreach ($group as $store) {
                     if (isset($store['copy_from'])) {
                         $copyFrom = $store['copy_from'];
                         $copyTo = (isset($store['copy_to'])) ? $store['copy_to'] : 0;
                         if ($copyTo) {
                             $this->_objectManager->create(\Magento\Catalog\Model\Product::class)
                                 ->setStoreId($copyFrom)
                                 ->load($productId)
                                 ->setStoreId($copyTo)
                                 ->setCanSaveCustomOptions($data['can_save_custom_options'])
                                 ->setCopyFromView(true)
                                 ->save();
                         }
                     }
                 }
             }
         }
     }
 }
 
 private function getCategoryLinkManagement()
 {
     if (null === $this->categoryLinkManagement) {
         $this->categoryLinkManagement = \Magento\Framework\App\ObjectManager::getInstance()
             ->get(\Magento\Catalog\Api\CategoryLinkManagementInterface::class);
     }
     return $this->categoryLinkManagement;
 }
 
 private function getStoreManager()
 {
     if (null === $this->storeManager) {
         $this->storeManager = \Magento\Framework\App\ObjectManager::getInstance()
             ->get(\Magento\Store\Model\StoreManagerInterface::class);
     }
     return $this->storeManager;
 }
 
 protected function getDataPersistor()
 {
     if (null === $this->dataPersistor) {
         $this->dataPersistor = $this->_objectManager->get(DataPersistorInterface::class);
     }
 
     return $this->dataPersistor;
 }
 
 private function persistMediaData(ProductInterface $product, array $data)
 {
     $mediaGallery = $product->getData('media_gallery');
     if (!empty($mediaGallery['images'])) {
         foreach ($mediaGallery['images'] as $key => $image) {
             if (!isset($image['new_file'])) {
                 //Remove duplicates.
                 unset($mediaGallery['images'][$key]);
             }
         }
         $data['product']['media_gallery'] = $mediaGallery;
         $fields = [
             'image',
             'small_image',
             'thumbnail',
             'swatch_image',
         ];
         foreach ($fields as $field) {
             $data['product'][$field] = $product->getData($field);
         }
     }
 
     return $data;
 }  }