<?php

namespace CanalTP\AMQPMttWorkers;

use CanalTP\MediaManager\Company\Company;
use CanalTP\MediaManager\Company\Configuration\Builder\ConfigurationBuilder;
use CanalTP\MediaManager\Media\Factory\MediaFactory;
use CanalTP\MttBundle\MediaManager\Category\Factory\CategoryFactory;
use CanalTP\MttBundle\MediaManager\Category\CategoryType;
use CanalTP\MttBundle\Services\MediaManager as MttMediaManager;

class TimetableMediaBuilder {

    private $config;
    private $company;
    private $mediaFactory;

    public function __construct()
    {
        // TODO: retrieve this from yaml configuration inside Mtt (right now it's in SamApp...)
        $this->config = array(
            'name' => MEDIA_NAME,
            'storage' => array(
                'type' => MEDIA_STORAGE_TYPE,
                'path' => MEDIA_STORAGE_PATH,
            ),
            'strategy' => MEDIA_STRATEGY
        );
        $this->mediaFactory = new MediaFactory();
        $this->company = new Company();
        $configurationBuilder = new ConfigurationBuilder();
        $this->categoryFactory = new CategoryFactory();

        $this->company->setConfiguration($configurationBuilder->buildConfiguration($this->config));
        $this->company->setName($this->config['name']);

    }

    private function getCategory($externalNetworkId, $externalRouteId, $externalStopPointId, $seasonId)
    {
        $networkCategory = $this->categoryFactory->create(CategoryType::NETWORK);
        $networkCategory->setId($externalNetworkId);

        $routeCategory = $this->categoryFactory->create(CategoryType::ROUTE);
        $routeCategory->setId($externalRouteId);
        $routeCategory->setParent($networkCategory);

        $stopPointCategory = $this->categoryFactory->create(CategoryType::STOP_POINT);
        $stopPointCategory->setId($externalStopPointId);
        $stopPointCategory->setParent($routeCategory);

        $seasonCategory = $this->categoryFactory->create(CategoryType::SEASON);
        $seasonCategory->setId($seasonId);
        $seasonCategory->setParent($stopPointCategory);

        return $seasonCategory;
    }

    public function saveFile($filePath, $externalNetworkId, $externalRouteId, $externalStopPointId, $seasonId)
    {
        $category = $this->getCategory($externalNetworkId, $externalRouteId, $externalStopPointId, $seasonId);
        $media = $this->mediaFactory->create($filePath);
        $media->setFileName(MttMediaManager::TIMETABLE_FILENAME);
        $media->setBaseName(MttMediaManager::TIMETABLE_FILENAME . '_tmp.pdf');
        $media->setSize(filesize($filePath));
        $media->setPath($filePath);
        $media->setCompany($this->company);
        $media->setCategory($category);

        $result = $this->company->addMedia($media);

        return $result ? $media->getPath() : false;
    }
}
