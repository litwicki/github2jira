<?php namespace App\Common;

use GuzzleHttp\Psr7\Response;

class Github2JiraHelpers
{
    /**
     * @param Response $response
     * @return array
     */
    public static function responseToArray(Response $response)
    {
        $json = $response->getBody();
        $response = json_decode($json, TRUE);
        //force an array structure
        $response = !is_array($response) ? array($response) : $response;

        return $response;
    }
}