<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * This is a custom implementation of JsonResponse to replace the Symfony component
 * until the Symfony dependencies are properly installed.
 */
class JsonResponse extends Response
{
    protected $data;

    /**
     * Constructor.
     *
     * @param mixed $data    The response data
     * @param int   $status  The response status code
     * @param array $headers An array of response headers
     */
    public function __construct($data = null, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        if (null !== $data) {
            $this->setData($data);
        }
    }

    /**
     * Sets the data to be sent as JSON.
     *
     * @param mixed $data
     *
     * @return $this
     */
    public function setData($data = [])
    {
        $this->data = $data;

        // Encode <, >, ', &, and " characters in the JSON, making it also safe to be embedded into HTML.
        $this->setContent(json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));

        // Set the content type header
        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', 'application/json');
        }

        return $this;
    }

    /**
     * Returns the original data.
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}