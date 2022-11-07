<?php

namespace ArieTimmerman\Laravel\SCIMServer\Middleware;

use Closure;
use Illuminate\Http\Request;
use ArieTimmerman\Laravel\SCIMServer\Exceptions\SCIMException;

function undefault_schema(\stdClass $parsed_json)
{
    foreach($parsed_json AS $key => $value) {
        if (stristr($key,'urn:ietf:params:scim:schemas:core:') !== false) {
            \Log::error("Found the schema key! It's: $key");
            unset($parsed_json->{$key}); //yank it out
            foreach($value AS $subkey => $subval) { //iterate through *its* subkey/subvals...
                // TODO should we check if the original keys exist? ONly overwrite them if they don't?
                $parsed_json->{$subkey} = $subval;
            }
        } else {
            \Log::error("didn't find schema key in $key");
        }
    }
    return $parsed_json; // FIXME - actually, uh, do the thing?
}

class SCIMHeaders
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->method() != 'GET' && stripos($request->header('content-type'), 'application/scim+json') === false && stripos($request->header('content-type'), 'application/json') === false && strlen($request->getContent()) > 0) {
            throw new SCIMException(sprintf('The content-type header should be set to "%s"', 'application/scim+json'));
        }
        
        $response = $next($request);
        \Log::error("Response is: ".print_r($response->content(),true));
        $response_content = json_decode($response->content());

        if ( ! $response_content->totalResults) {
            \Log::error("doing regular response parsing");
            $response->setContent(json_encode(undefault_schema($response_content)));
        } else {
            \Log::error("doing array-ish work on response...");
            $final_response = [];
            foreach($response_content->Resources AS $index => $object) {
                $final_response[] = undefault_schema($object);
            }
            $response_content->Resources = $final_response;
            $response->setContent(json_encode($response_content));
//        } else {
//            \Log::error("UNKNOWN SCHEMA TYPE!!!! What's going on here?");
        }
        
        return $response->header('Content-Type', 'application/scim+json');
    }
}
