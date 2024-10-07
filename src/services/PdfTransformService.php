<?php
/**
 * PDF Transform plugin for Craft CMS 3.x
 *
 * @link      http://bymayo.co.uk
 * @copyright Copyright (c) 2018 ByMayo
 */

namespace bymayo\pdftransform\services;

use bymayo\pdftransform\PdfTransform;

use Imagick;
use Craft;
use craft\base\Component;
use Yii;
use yii\base\Exception;
use craft\services\Volumes;
use Spatie\PdfToImage\Pdf;
use craft\elements\Asset;
use craft\helpers\Path;

/**
 * @author    ByMayo
 * @package   PdfTransform
 * @since     1.0.0
 */
class PdfTransformService extends Component
{

    private $settings;

    // Public Methods
    // =========================================================================

    public function __construct() {
      $this->settings = PdfTransform::$plugin->getSettings();
    }

    public function getVolumeOptions()
    {

      $volumesArray = array(
        array(
            'label' => 'Select a volume',
            'value' => null
          )
      );
      $volumes = new Volumes;

      foreach ($volumes->getAllVolumes() as $volume) {

        $volumeArray = array();
        $volumeArray['label'] = $volume->name;
        $volumeArray['value'] = $volume->id;
        array_push($volumesArray, $volumeArray);

      }

      return $volumesArray;

    }

    public function getImageVolume()
    {
      $imageVolumeId = $this->settings->imageVolume;

      if ($imageVolumeId) {
        $volume = Craft::$app->getVolumes()->getVolumeById($imageVolumeId);
        return $volume;
      }

      throw new Exception('PDF Transform: No output image volume selected in settings');
   }

   public function getFileName($asset)
   {
      // e.g. filename-12345.jpg
      return $asset->filename . '-' . $asset->id . '.' . $this->settings->imageFormat;
   }

    public function render($asset)
    {

      $volume = $this->getImageVolume();
      $fileName = $this->getFileName($asset);

      if ($volume->fileExists($fileName)) {
        
        $transformedAsset = Asset::find()
          ->volumeId($volume->id)
          ->filename($fileName)
          ->one();

        return $transformedAsset;

      }

      return $this->pdfToImage(
        $asset
      );

    }

    public function pdfToImage($asset)
    {

      $filename = $this->getFileName($asset);
      $volume = $this->getImageVolume();

      try {

        $pathService = Craft::$app->getPath();
        $tempPath = $pathService->getTempPath(true) . '/' . mt_rand(0, 9999999) . '.png';


        // ----------------------------------------------------------
        // JG EDIT: Allow for download without SSL check for local 
        // usage, where SSL may not be available
        
        $stream_opts = [
          "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false,
          ],
        ];
        
        $response = file_get_contents($asset->url, false, stream_context_create($stream_opts));
        
        file_put_contents($tempPath, $response);
        // file_put_contents($tempPath, file_get_contents($asset->url));
        // ----------------------------------------------------------

  
        $tempPathTransform = $pathService->getTempPath(true) . '/' . $filename;
  
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        $pdf = new Pdf($tempPath);

        $pdf
          ->setPage($this->settings->page)
          ->setResolution($this->settings->imageResolution)
          ->setCompressionQuality($this->settings->imageQuality)
          ->saveImage($tempPathTransform);

        $assetTransformed = new Asset();
        $assetTransformed->tempFilePath = $tempPathTransform;
        $assetTransformed->filename = $filename;
        $assetTransformed->folderId = $folder->id;
        $assetTransformed->newFolderId = $folder->id;
        $assetTransformed->kind = 'Image';
        $assetTransformed->title = $asset->title;
        $assetTransformed->avoidFilenameConflicts = true;
        $assetTransformed->setVolumeId($volume->id);
        $assetTransformed->setScenario(Asset::SCENARIO_CREATE);

        $assetTransformed->validate();
          
        if (Craft::$app->getElements()->saveElement($assetTransformed, false))
        {
          return $assetTransformed;
        }

      }  
      catch (Exception $e) {
        throw new Exception('PDF Transform: Could not transform PDF to image');
      }


    }

}
