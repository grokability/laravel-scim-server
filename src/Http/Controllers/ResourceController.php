<?php

namespace ArieTimmerman\Laravel\SCIMServer\Http\Controllers;

use ArieTimmerman\Laravel\SCIMServer\SCIM\ListResponse;
use Illuminate\Http\Request;
use ArieTimmerman\Laravel\SCIMServer\Helper;
use ArieTimmerman\Laravel\SCIMServer\Exceptions\SCIMException;
use Tmilos\ScimFilterParser\Parser;
use Tmilos\ScimFilterParser\Mode;
use ArieTimmerman\Laravel\SCIMServer\ResourceType;
use Illuminate\Database\Eloquent\Model;
use ArieTimmerman\Laravel\SCIMServer\Events\Delete;
use ArieTimmerman\Laravel\SCIMServer\Events\Get;
use ArieTimmerman\Laravel\SCIMServer\Events\Create;
use ArieTimmerman\Laravel\SCIMServer\Events\Replace;
use ArieTimmerman\Laravel\SCIMServer\Events\Patch;
use ArieTimmerman\Laravel\SCIMServer\SCIM\Schema;
use ArieTimmerman\Laravel\SCIMServer\PolicyDecisionPoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Log;

class ResourceController extends Controller
{
    protected static function isAllowed(PolicyDecisionPoint $pdp, Request $request, $operation, array $attributes, ResourceType $resourceType, ?Model $resourceObject, $isMe = false)
    {
        return $pdp->isAllowed($request, $operation, $attributes, $resourceType, $resourceObject, $isMe);
    }

    protected static function replaceKeys(array $input)
    {
        $return = array();
        foreach ($input as $key => $value) {
            if (strpos($key, '_') > 0) {
                $key = str_replace('___', '.', $key);
            }

            if (is_array($value)) {
                $value = self::replaceKeys($value);
            }

            $return[$key] = $value;
        }
        return $return;
    }

    protected static function validateScim(ResourceType $resourceType, $flattened, ?Model $resourceObject)
    {
        $objectPreparedForValidation = [];
        $validations = $resourceType->getValidations();
        $simpleValidations = [];

        /**
         * Dots have a different meaning in SCIM and in Laravel's validation logic
         */
        foreach ($flattened as $key => $value) {
            $objectPreparedForValidation[preg_replace('/([^*])\.([^*])/', '${1}___${2}', $key)] = $value;
        }

        foreach ($validations as $key => $value) {
            $simpleValidations[
                preg_replace('/([^*])\.([^*])/', '${1}___${2}', $key)
                ] = !is_string($value) ? $value : ($resourceObject != null ? preg_replace('/,\[OBJECT_ID\]/', ','.$resourceObject->id, $value) : str_replace(',[OBJECT_ID]', '', $value));
        }

        $validator = Validator::make($objectPreparedForValidation, $simpleValidations);

        if ($validator->fails()) {
            $e = $validator->errors();
            $e = self::replaceKeys($e->toArray());

            throw (new SCIMException('Invalid data!'))->setCode(400)->setScimType('invalidSyntax')->setErrors($e);
        }

        $validTemp = $validator->validate();
        $valid = [];

        $keys = collect($simpleValidations)->keys()->map(
            function ($rule) {
                return explode('.', $rule)[0];
            }
        )->unique()->toArray();

        foreach ($keys as $key) {
            if (array_key_exists($key, $validTemp)) {
                $valid[$key] = $validTemp[$key];
            }
        }

        $flattened = [];
        foreach ($valid as $key => $value) {
            $flattened[str_replace(['___'], ['.'], $key)] = $value;
        }

        return $flattened;
    }

    public static function createFromSCIM($resourceType, $input, PolicyDecisionPoint $pdp = null, Request $request = null, $allowAlways = false, $isMe = false)
    {
        if (!isset($input['schemas']) || !is_array($input['schemas'])) {
            throw (new SCIMException('Missing a valid schemas-attribute.'))->setCode(500);
        }

        $flattened = Helper::flatten($input, $input['schemas']);
        $flattened = self::validateScim($resourceType, $flattened, null);

        if (!$allowAlways && !self::isAllowed($pdp, $request, PolicyDecisionPoint::OPERATION_POST, $flattened, $resourceType, null, $isMe)) {
            throw (new SCIMException('This is not allowed'))->setCode(403);
        }

        $class = $resourceType->getClass();

        /**
         * @var Model
        */
        $resourceObject = $class::firstOrNew(['username' => $input['userName']]);

        $allAttributeConfigs = [];

        foreach ($flattened as $scimAttribute => $value) {
            $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $scimAttribute);
            $attributeConfig->add($value, $resourceObject);
            $allAttributeConfigs[] = $attributeConfig;
        }

        try {
            $resourceObject->save();
        } catch (QueryException $exception) {
            throw $exception;
            // throw new SCIMException('Could not save this');
        }

        foreach ($allAttributeConfigs as &$attributeConfig) {
            $attributeConfig->writeAfter($flattened[$attributeConfig->getFullKey()], $resourceObject);
        }

        return $resourceObject;
    }

    /**
     * @return Model
     */
    public function createObject(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, $isMe = false)
    {
        $input = $request->input();

        $resourceObject = self::createFromSCIM($resourceType, $input, $pdp, $request, false, $isMe);

        event(new Create($resourceObject, $isMe));

        return $resourceObject;
    }

    /**
     * Log a SCIM controller method invocation (if configured)
     * 
     */
    public function scimlog(Callable $function, Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, ...$params)
    {
        // I really wanted to include the 'Model' up there in the signature, but the index method doesn't have a model
        // and I realized I can derive enough of the model information from the URL, so I figured this way is okay,
        // and the above signature *will* work for any of the SCIM methods we have here in this controller.

        // Also the Callable $function expects the value of $this as the first parameter, which, in all of the
        // function definitions, we call $that - to avoid naming conflicts with $this. It's a little weird. Keep an
        // eye out (also comments embedded in each invocation of this method, just to be clear.
        if (config('scim.trace')) {
            try {
                $response = $function($this, $request, $pdp, $resourceType, ...$params);
                $response_text = method_exists($response, 'toJson') ? $response->toJson() : $response; // very not sure about this; not sure if other responses will parse right - FIXME
                $logmsg = <<< EOF
                =====================================================================================
                {$request->method()} {$request->url()}
                
                {$request->getContent()}
                -------------------------------------------------------------------------------------
                $response_text
                EOF;
                Log::channel('scimtrace')->info($logmsg);
                return $response;
            } catch (\Throwable $e) {
                $error_class = get_class($e);
                Log::channel('scimtrace')->error(<<<EOF
                =====================================================================================
                Exception caught! {$e->getMessage()} of type: $error_class when executing:
                {$request->method()} {$request->url()}

                {$request->getContent()}
                EOF);
            }
        } else {
            return $function($this, $request, $pdp, $resourceType, ...$params);
        }
    }


    /**
     * Create a new scim resource
     *
     * @param  Request      $request
     * @param  ResourceType $resourceType
     * @throws SCIMException
     * @return \Symfony\Component\HttpFoundation\Response|\Illuminate\Contracts\Routing\ResponseFactory
     */
    public function create(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, $isMe = false)
    {
        return $this->scimlog(function ($that, $request,  $pdp, $resourceType, $isMe) {
            /* we have to pass $that (which will be the value of $this) because scimlog takes a *function* not a method,
               so we don't have $this available */
            $resourceObject = $that->createObject($request, $pdp, $resourceType, $isMe);

            return Helper::objectToSCIMCreateResponse($resourceObject, $resourceType);

        }, $request, $pdp, $resourceType, $isMe); /* okay *HERE* I don't need it, right? */
    }

    public function show(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject)
    {
        return $this->scimlog(function ($that, $request, $pdp, $resourceType, $resourceObject) {
            /* we have to pass $that (which will be the value of $this) because scimlog takes a *function* not a method,
               so we don't have $this available */
            event(new Get($resourceObject));

            return Helper::objectToSCIMResponse($resourceObject, $resourceType);
        },$request, $pdp, $resourceType, $resourceObject);
    }

    public function delete(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject)
    {
        return $this->scimlog(function ($that, $request, $pdp, $resourceType, $resourceObject) {
            /* we have to pass $that (which will be the value of $this) because scimlog takes a *function* not a method,
               so we don't have $this available */
            $resourceObject->delete();

            event(new Delete($resourceObject));

            return response(null, 204);

        }, $request, $pdp, $resourceType, $resourceObject);
    }

    public function replace(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject, $isMe = false)
    {
        return $this->scimlog(function ($that, $request, $pdp, $resourceType, $resourceObject, $isMe) {
            /* we have to pass $that (which will be the value of $this) because scimlog takes a *function* not a method,
               so we don't have $this available */
            $originalRaw = Helper::objectToSCIMArray($resourceObject, $resourceType);
            $original = Helper::flatten($originalRaw, $request->input()['schemas']);

            //TODO: get flattend from $resourceObject
            $flattened = Helper::flatten($request->input(), $request->input()['schemas']);
            $flattened = $that->validateScim($resourceType, $flattened, $resourceObject);

            $updated = [];

            foreach ($flattened as $key => $value) {
                if (!isset($original[$key]) || json_encode($original[$key]) != json_encode($flattened[$key])) {
                    $updated[$key] = $flattened[$key];
                }
            }

            if (!self::isAllowed($pdp, $request, PolicyDecisionPoint::OPERATION_PUT, $updated, $resourceType, null)) {
                throw new SCIMException('This is not allowed');
            }

            //Keep an array of written values
            $uses = [];

            //Write all values
            foreach ($flattened as $scimAttribute => $value) {
                $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $scimAttribute);

                if ($attributeConfig->isWriteSupported()) {
                    $attributeConfig->replace($value, $resourceObject);
                }

                $uses[] = $attributeConfig;
            }

            //Find values that have not been written in order to empty these.
            $allAttributeConfigs = $resourceType->getAllAttributeConfigs();

            foreach ($uses as $use) {
                foreach ($allAttributeConfigs as $key => $option) {
                    if ($use->getFullKey() == $option->getFullKey()) {
                        unset($allAttributeConfigs[$key]);
                    }
                }
            }

            foreach ($allAttributeConfigs as $attributeConfig) {
                // Do not write write-only attribtues (such as passwords)
                if ($attributeConfig->isReadSupported() && $attributeConfig->isWriteSupported()) {
                    //   $attributeConfig->remove($resourceObject);
                }
            }

            $resourceObject->save();

            event(new Replace($resourceObject, $isMe, $originalRaw));

            return Helper::objectToSCIMResponse($resourceObject, $resourceType);
        }, $request, $pdp, $resourceType, $resourceObject, $isMe);
    }

    public function update(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject, $isMe = false)
    {
        return $this->scimlog(function ($that, $request, $pdp, $resourceType, $resourceObject, $isMe) {
            /* we have to pass $that (which will be the value of $this) because scimlog takes a *function* not a method,
               so we don't have $this available */
            $input = $request->input();

            if ($input['schemas'] !== ["urn:ietf:params:scim:api:messages:2.0:PatchOp"]) {
                throw (new SCIMException(sprintf('Invalid schema "%s". MUST be "urn:ietf:params:scim:api:messages:2.0:PatchOp"', json_encode($input['schemas']))))->setCode(404);
            }

            if (isset($input['urn:ietf:params:scim:api:messages:2.0:PatchOp:Operations'])) {
                $input['Operations'] = $input['urn:ietf:params:scim:api:messages:2.0:PatchOp:Operations'];
                unset($input['urn:ietf:params:scim:api:messages:2.0:PatchOp:Operations']);
            }

            $oldObject = Helper::objectToSCIMArray($resourceObject, $resourceType);

            foreach ($input['Operations'] as $operation) {
                switch (strtolower($operation['op'])) {
                    case "add":
                        if (isset($operation['path'])) {
                            $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $operation['path']);
                            foreach ((array)$operation['value'] as $value) {
                                $attributeConfig->add($value, $resourceObject);
                            }
                        } else {
                            foreach ((array)$operation['value'] as $key => $value) {
                                $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $key);

                                foreach ((array)$value as $v) {
                                    $attributeConfig->add($v, $resourceObject);
                                }
                            }
                        }

                        break;

                    case "remove":
                        if (isset($operation['path'])) {
                            $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $operation['path']);
                            $attributeConfig->remove($operation['value'] ?? null, $resourceObject);
                        } else {
                            throw new SCIMException('You MUST provide a "Path"');
                        }


                        break;

                    case "replace":
                        if (isset($operation['path'])) {
                            // Removed this check. A replace with a null/empty value should be valid.
                            // if(!isset($operation['value'])){
                            //     throw new SCIMException('Please provide a "value"',400);
                            // }

                            $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $operation['path']);
                            $attributeConfig->replace($operation['value'], $resourceObject);
                        } else {
                            foreach ((array)$operation['value'] as $key => $value) {
                                $attributeConfig = Helper::getAttributeConfigOrFail($resourceType, $key);
                                $attributeConfig->replace($value, $resourceObject);
                            }
                        }

                        break;

                    default:
                        throw new SCIMException(sprintf('Operation "%s" is not supported', $operation['op']));
                }
            }

            $dirty = $resourceObject->getDirty();

            // TODO: prevent something from getten written before ...
            $newObject = Helper::flatten(Helper::objectToSCIMArray($resourceObject, $resourceType), $resourceType->getSchema());

            $flattened = $that->validateScim($resourceType, $newObject, $resourceObject);

            if (!self::isAllowed($pdp, $request, PolicyDecisionPoint::OPERATION_PATCH, $flattened, $resourceType, null)) {
                throw new SCIMException('This is not allowed');
            }

            $resourceObject->save();

            event(new Patch($resourceObject, $isMe, $oldObject));

            return Helper::objectToSCIMResponse($resourceObject, $resourceType);
        }, $request, $pdp, $resourceType, $resourceObject, $isMe);
    }


    public function notImplemented(Request $request)
    {
        return response(null, 501);
    }

    public function wrongVersion(Request $request)
    {
        throw (new SCIMException('Only SCIM v2 is supported. Accessible under ' . url('scim/v2')))->setCode(501)
            ->setScimType('invalidVers');
    }

    public function index(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType)
    {
        return $this->scimlog(function ($that, $request, $pdp, $resourceType) {
            /* we have to pass $that (which will be the value of $this) because scimlog takes a *function* not a method,
               so we don't have $this available */
            $class = $resourceType->getClass();

            // The 1-based index of the first query result. A value less than 1 SHALL be interpreted as 1.
            $startIndex = max(1, intVal($request->input('startIndex', 0)));

            // Non-negative integer. Specifies the desired maximum number of query results per page, e.g., 10. A negative value SHALL be interpreted as "0". A value of "0" indicates that no resource results are to be returned except for "totalResults".
            $count = max(0, intVal($request->input('count', 10)));

            $sortBy = null;

            if ($request->input('sortBy')) {
                $sortBy = Helper::getEloquentSortAttribute($resourceType, $request->input('sortBy'));
            }

            $resourceObjectsBase = $class::when(
                $filter = $request->input('filter'),
                function ($query) use ($filter, $resourceType) {
                    $parser = new Parser(Mode::FILTER());

                    try {
                        $node = $parser->parse($filter);

                        Helper::scimFilterToLaravelQuery($resourceType, $query, $node);
                    } catch (\Tmilos\ScimFilterParser\Error\FilterException $e) {
                        throw (new SCIMException($e->getMessage()))->setCode(400)->setScimType('invalidFilter');
                    }
                }
            );

            $totalResults = $resourceObjectsBase->count();

            $resourceObjects = $resourceObjectsBase->skip($startIndex - 1)->take($count);

            $resourceObjects = $resourceObjects->with($resourceType->getWithRelations());

            if ($sortBy != null) {
                $direction = $request->input('sortOrder') == 'descending' ? 'desc' : 'asc';

                $resourceObjects = $resourceObjects->orderBy($sortBy, $direction);
            }

            $resourceObjects = $resourceObjects->get();


            $attributes = [];
            $excludedAttributes = [];

            return new ListResponse($resourceObjects, $startIndex, $totalResults, $attributes, $excludedAttributes, $resourceType);
        }, $request, $pdp, $resourceType);
    }
}
