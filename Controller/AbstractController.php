<?php

namespace Oneup\UploaderBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use Oneup\UploaderBundle\UploadEvents;
use Oneup\UploaderBundle\Event\PreUploadEvent;
use Oneup\UploaderBundle\Event\PostPersistEvent;
use Oneup\UploaderBundle\Event\PostUploadEvent;
use Oneup\UploaderBundle\Event\ValidationEvent;
use Oneup\UploaderBundle\Uploader\Storage\StorageInterface;
use Oneup\UploaderBundle\Uploader\Response\ResponseInterface;
use Oneup\UploaderBundle\Uploader\Exception\ValidationException;

abstract class AbstractController
{
    protected $container;
    protected $storage;
    protected $config;
    protected $type;

    public function __construct(ContainerInterface $container, StorageInterface $storage, array $config, $type)
    {
        $this->container = $container;
        $this->storage = $storage;
        $this->config = $config;
        $this->type = $type;
    }

    abstract public function upload();

    public function progress()
    {
        $request = $this->container->get('request');
        $session = $this->container->get('session');

        $prefix = ini_get('session.upload_progress.prefix');
        $name   = ini_get('session.upload_progress.name');

        // assemble session key
        // ref: http://php.net/manual/en/session.upload-progress.php
        $key = sprintf('%s.%s', $prefix, $name);
        $value = $session->get($key);

        return new JsonResponse($value);
    }

    /**
     *  This internal function handles the actual upload process
     *  and will most likely be called from the upload()
     *  function in the implemented Controller.
     *
     *  Note: The return value differs when
     *
     *  @param UploadedFile The file to upload
     *  @param response A response object.
     *  @param request The request object.
     */
    protected function handleUpload(UploadedFile $file, ResponseInterface $response, Request $request)
    {
        $this->validate($file);

        $this->dispatchPreUploadEvent($file, $response, $request);

        // no error happend, proceed
        $namer = $this->container->get($this->config['namer']);
        $name  = $namer->name($file);

        // perform the real upload
        $uploaded = $this->storage->upload($file, $name);

        $this->dispatchPostEvents($uploaded, $response, $request);
    }

    /**
     *  This function is a helper function which dispatches pre upload event
     *
     *  @param uploaded The uploaded file.
     *  @param response A response object.
     *  @param request The request object.
     */
    protected function dispatchPreUploadEvent(UploadedFile $uploaded, ResponseInterface $response, Request $request)
    {
        $dispatcher = $this->container->get('event_dispatcher');

        // dispatch pre upload event (both the specific and the general)
        $postUploadEvent = new PreUploadEvent($uploaded, $response, $request, $this->type, $this->config);
        $dispatcher->dispatch(UploadEvents::PRE_UPLOAD, $postUploadEvent);
        $dispatcher->dispatch(sprintf('%s.%s', UploadEvents::PRE_UPLOAD, $this->type), $postUploadEvent);
    }

    /**
     *  This function is a helper function which dispatches post upload
     *  and post persist events.
     *
     *  @param uploaded The uploaded file.
     *  @param response A response object.
     *  @param request The request object.
     */
    protected function dispatchPostEvents($uploaded, ResponseInterface $response, Request $request)
    {
        $dispatcher = $this->container->get('event_dispatcher');

        // dispatch post upload event (both the specific and the general)
        $postUploadEvent = new PostUploadEvent($uploaded, $response, $request, $this->type, $this->config);
        $dispatcher->dispatch(UploadEvents::POST_UPLOAD, $postUploadEvent);
        $dispatcher->dispatch(sprintf('%s.%s', UploadEvents::POST_UPLOAD, $this->type), $postUploadEvent);

        if (!$this->config['use_orphanage']) {
            // dispatch post persist event (both the specific and the general)
            $postPersistEvent = new PostPersistEvent($uploaded, $response, $request, $this->type, $this->config);
            $dispatcher->dispatch(UploadEvents::POST_PERSIST, $postPersistEvent);
            $dispatcher->dispatch(sprintf('%s.%s', UploadEvents::POST_PERSIST, $this->type), $postPersistEvent);
        }
    }

    protected function validate(UploadedFile $file)
    {
        $dispatcher = $this->container->get('event_dispatcher');
        $event = new ValidationEvent($file, $this->config, $this->type);

        try {
            $dispatcher->dispatch(UploadEvents::VALIDATION, $event);
        } catch (ValidationException $exception) {
            // pass the exception one level up
            throw new UploadException($exception->getMessage());
        }
    }
}
