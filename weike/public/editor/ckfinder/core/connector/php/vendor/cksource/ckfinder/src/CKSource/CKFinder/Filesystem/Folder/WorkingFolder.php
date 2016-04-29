<?php

/*
 * CKFinder
 * ========
 * http://cksource.com/ckfinder
 * Copyright (C) 2007-2015, CKSource - Frederico Knabben. All rights reserved.
 *
 * The software, this file and its contents are subject to the CKFinder
 * License. Please read the license.txt file before using, installing, copying,
 * modifying or distribute this file or part of its contents. The contents of
 * this file is part of the Source Code of CKFinder.
 */

namespace CKSource\CKFinder\Filesystem\Folder;

use CKSource\CKFinder\Backend\Backend;
use CKSource\CKFinder\CKFinder;
use CKSource\CKFinder\Event\CKFinderEvent;
use CKSource\CKFinder\Event\CreateFolderEvent;
use CKSource\CKFinder\Event\RenameFolderEvent;
use CKSource\CKFinder\Exception\AccessDeniedException;
use CKSource\CKFinder\Exception\AlreadyExistsException;
use CKSource\CKFinder\Exception\FolderNotFoundException;
use CKSource\CKFinder\Exception\InvalidNameException;
use CKSource\CKFinder\Exception\InvalidRequestException;
use CKSource\CKFinder\Filesystem\File\File;
use CKSource\CKFinder\Filesystem\Path;
use CKSource\CKFinder\ResourceType\ResourceType;
use CKSource\CKFinder\Response\JsonResponse;
use CKSource\CKFinder\ResizedImage\ResizedImageRepository;
use CKSource\CKFinder\Utils;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use CKSource\CKFinder\Thumbnail\ThumbnailRepository;

/**
 * Class WorkingFolder
 *
 * Represents a working folder for current request defined by
 * resource type and relative path
 */
class WorkingFolder extends Folder implements EventSubscriberInterface
{
    /**
     * @var CKFinder $app
     */
    protected $app;

    /**
     * @var ThumbnailRepository
     */
    protected $thumbnailRepository;

    /**
     * @var ResourceType $resourceType
     */
    protected $resourceType;

    /**
     * Current folder path
     *
     * @var string $clientCurrentFolder
     */
    protected $clientCurrentFolder;

    /**
     * Backend relative path (includes backend directory prefix)
     *
     * @var string $path
     */
    protected $path;

    /**
     * Directory acl mask computed for current user
     *
     * @var int|null $aclMask
     */
    protected $aclMask = null;

    /**
     * Constructor
     *
     * @param CKFinder     $app
     *
     * @throws \Exception
     */
    function __construct(CKFinder $app)
    {
        $this->app = $app;

        /* @var $request \Symfony\Component\HttpFoundation\Request */
        $request = $app['request_stack']->getCurrentRequest();

        $this->resourceType = $app['resource_type_factory']->getResourceType((string) $request->get('type'));

        $this->clientCurrentFolder = Path::normalize(trim((string) $request->get('currentFolder')));

        if (!Path::isValid($this->clientCurrentFolder)) {
            throw new InvalidNameException('Invalid path');
        }

        $resourceTypeDirectory = $this->resourceType->getDirectory();

        $this->path = Path::combine($resourceTypeDirectory, $this->clientCurrentFolder);

        $this->backend = $this->resourceType->getBackend();
        $this->thumbnailRepository = $app['thumbnail_repository'];

        $backend = $this->getBackend();

        // Check if folder path is not hidden
        if ($backend->isHiddenPath($this->getClientCurrentFolder())) {
            throw new InvalidRequestException('Hidden folder path used');
        }

        // Check if resource type folder exists - if not then create it
        if (!empty($resourceTypeDirectory) && !$backend->hasDirectory($this->path)) {
            if ($this->clientCurrentFolder === '/') {
                @$backend->createDir($resourceTypeDirectory);

                if (!$backend->hasDirectory($resourceTypeDirectory)) {
                    throw new AccessDeniedException("Couldn't create resource type directory. Please check permissions.");
                }
            } else {
                throw new FolderNotFoundException();
            }
        }
    }

    /**
     * Returns ResourceType object for current working folder
     *
     * @return ResourceType
     */
    public function getResourceType()
    {
        return $this->resourceType;
    }

    /**
     * Returns name of current resource type
     *
     * @return string
     */
    public function getResourceTypeName()
    {
        return $this->resourceType->getName();
    }

    /**
     * Returns client current folder path
     *
     * @return string
     */
    public function getClientCurrentFolder()
    {
        return $this->clientCurrentFolder;
    }

    /**
     * Returns backend relative path with resource type directory prefix
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Returns backend assigned for current resource type
     *
     * @return Backend
     */
    public function getBackend()
    {
        return $this->resourceType->getBackend();
    }

    /**
     * Returns thumbnails repository object
     *
     * @return ThumbnailRepository
     */
    public function getThumbnailsRepository()
    {
        return $this->thumbnailRepository;
    }

    /**
     * Lists directories in current working folder
     *
     * @return array list of directories
     */
    public function listDirectories()
    {
        return $this->getBackend()->directories($this->getResourceType(), $this->getClientCurrentFolder());
    }

    /**
     * Lists files in current working folder
     *
     * @return array list of files
     */
    public function listFiles()
    {
        return $this->getBackend()->files($this->getResourceType(), $this->getClientCurrentFolder());
    }

    /**
     * Returns acl mask computed for current user and current working folder
     *
     * @return int
     */
    public function getAclMask()
    {
        if (null === $this->aclMask) {
            $this->aclMask = $this->app->getAcl()->getComputedMask($this->getResourceTypeName(), $this->getClientCurrentFolder());
        }

        return $this->aclMask;
    }

    /**
     * Creates a directory with given name in working folder
     *
     * @param string $dirname directory name
     *
     * @throws \Exception if directory couldn't be created
     */
    public function createDir($dirname)
    {
        $backend = $this->getBackend();

        if (!Folder::isValidName($dirname, $this->app['config']->get('disallowUnsafeCharacters')) || $backend->isHiddenFolder($dirname)) {
            throw new InvalidNameException('Invalid folder name');
        }

        $dirPath = Path::combine($this->getPath(), $dirname);

        if ($backend->hasDirectory($dirPath)) {
            throw new AlreadyExistsException('Folder already exists');
        }

        $dispatcher = $this->app['dispatcher'];

        $createFolderEvent = new CreateFolderEvent($this->app, $this, $dirname);

        $dispatcher->dispatch(CKFinderEvent::CREATE_FOLDER, $createFolderEvent);

        $dirname = $createFolderEvent->getNewFolderName();

        if (!$createFolderEvent->isPropagationStopped()) {
            $dirPath = Path::combine($this->getPath(), $dirname);

            if (!$backend->createDir($dirPath)) {
                throw new AccessDeniedException("Couldn't create new folder. Please check permissions.");
            }
        }


    }

    /**
     * Creates a file inside current working folder
     *
     * @param string $fileName file name
     * @param string $data     file data
     *
     * @return bool true if created successfully
     */
    public function write($fileName, $data)
    {
        $backend = $this->getBackend();
        $filePath = Path::combine($this->getPath(), $fileName);

        return $backend->write($filePath, $data);
    }

    /**
     * Creates a file inside current working folder using stream
     *
     * @param string   $fileName file name
     * @param resource $resource file data stream
     *
     * @return bool true if created successfully
     */
    public function writeStream($fileName, $resource)
    {
        $backend = $this->getBackend();
        $filePath = Path::combine($this->getPath(), $fileName);

        return $backend->writeStream($filePath, $resource);
    }

    /**
     * Creates or updates a file inside current working folder using stream
     *
     * @param string   $fileName file name
     * @param resource $resource file data stream
     *
     * @return bool true if updated successfully
     */
    public function putStream($fileName, $resource)
    {
        $backend = $this->getBackend();
        $filePath = Path::combine($this->getPath(), $fileName);

        return $backend->putStream($filePath, $resource);
    }

    /**
     * Checks if current working folder contains a file with given name
     *
     * @param string $fileName
     *
     * @return bool
     */
    public function containsFile($fileName)
    {
        $backend = $this->getBackend();

        if (!File::isValidName($fileName, $this->app['config']->get('disallowUnsafeCharacters')) || $backend->isHiddenFile($fileName)) {
            return false;
        }

        $filePath = Path::combine($this->getPath(), $fileName);

        return $backend->has($filePath);
    }

    /**
     * Returns contents of file with given name
     *
     * @param string $fileName
     *
     * @return string
     */
    public function read($fileName)
    {
        $backend = $this->getBackend();
        $filePath = Path::combine($this->getPath(), $fileName);

        return $backend->read($filePath);
    }

    /**
     * Returns contents stream of file with given name
     *
     * @param string $fileName
     *
     * @return resource
     */
    public function readStream($fileName)
    {
        $backend = $this->getBackend();
        $filePath = Path::combine($this->getPath(), $fileName);

        return $backend->readStream($filePath);
    }

    /**
     * Deletes current working folder
     *
     * @return bool if delete was successful
     */
    public function delete()
    {
        // Delete related thumbs path
        $this->thumbnailRepository->deleteThumbnails($this->resourceType, $this->getClientCurrentFolder());

        $this->app['cache']->deleteByPrefix(Path::combine($this->resourceType->getName(), $this->getClientCurrentFolder()));

        return $this->getBackend()->deleteDir($this->getPath());
    }

    /**
     * Renames current working folder
     *
     * @param string $newName new folder name
     *
     * @return array containing newName and newPath
     *
     * @throws \Exception if rename failed
     */
    public function rename($newName)
    {
        $disallowUnsafeCharacters  = $this->app['config']->get('disallowUnsafeCharacters');

        if (!Folder::isValidName($newName, $disallowUnsafeCharacters) || $this->backend->isHiddenFolder($newName)) {
            throw new InvalidNameException('Invalid folder name');
        }

        $newBackendPath = dirname($this->getPath()) . '/' . $newName;

        if ($this->backend->has($newBackendPath)) {
            throw new AlreadyExistsException('File already exists');
        }

        $newClientPath = Path::normalize(dirname($this->getClientCurrentFolder()) . '/' . $newName);

        $dispatcher = $this->app['dispatcher'];
        $renameFolderEvent = new RenameFolderEvent($this->app, $this, $newName);

        $dispatcher->dispatch(CKFinderEvent::RENAME_FOLDER, $renameFolderEvent);

        $newName = $renameFolderEvent->getNewFolderName();

        if (!$renameFolderEvent->isPropagationStopped()) {
            if (!$this->getBackend()->rename($this->getPath(), $newBackendPath)) {
                throw new AccessDeniedException();
            }

            // Delete related thumbs path
            $this->thumbnailRepository->deleteThumbnails($this->resourceType, $this->getClientCurrentFolder());

            $this->app['cache']->changePrefix(
                Path::combine($this->resourceType->getName(), $this->getClientCurrentFolder()),
                Path::combine($this->resourceType->getName(), $newClientPath));
        }

        return array(
            'newName' => $newName,
            'newPath' => $newClientPath
        );
    }

    public function getFileUrl($path)
    {
        return $this->backend->getFileUrl(Path::combine($this->resourceType->getDirectory(), $this->getClientCurrentFolder(), $path));
    }

    /**
     * @return ResizedImageRepository
     */
    public function getResizedImageRepository()
    {
        return $this->app['resized_image_repository'];
    }

    /**
     * Tells current WorkingFolder object to not add current folder
     * to the response.
     *
     * By default WorkingFolder object acts as events subscriber and
     * listens for KernelEvents::RESPONSE event. Given response is
     * then modified by adding info about current folder.
     *
     * @see WorkingFolder::addCurrentFolderInfo()
     */
    public function omitResponseInfo()
    {
        $this->app['dispatcher']->removeSubscriber($this);
    }

    /**
     * Adds current folder info to the response
     *
     * @param FilterResponseEvent $event
     */
    public function addCurrentFolderInfo(FilterResponseEvent $event)
    {
        /* @var JsonResponse $response */
        $response = $event->getResponse();

        if ($response instanceof JsonResponse) {
            $responseData = (array) $response->getData();

            $responseData = array(
                    'resourceType' => $this->getResourceTypeName(),
                    'currentFolder' => array(
                        'path' => $this->getClientCurrentFolder(),
                        'acl' => $this->getAclMask()
                    )
                ) + $responseData;

            $baseUrl = $this->backend->getBaseUrl();

            if (null !== $baseUrl) {
                $responseData['currentFolder']['url'] = Path::combine($baseUrl, Utils::encodeURLParts(Path::combine($this->resourceType->getDirectory(), $this->getClientCurrentFolder())));
            }

            $response->setData($responseData);
        }
    }

    /**
     * Returns listeners for event dispatcher
     *
     * @return array subscribed events
     */
    public static function getSubscribedEvents()
    {
        return array(KernelEvents::RESPONSE => array('addCurrentFolderInfo', 512));
    }
}