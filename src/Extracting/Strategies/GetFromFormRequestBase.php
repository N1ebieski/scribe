<?php

namespace Knuckles\Scribe\Extracting\Strategies;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;
use Knuckles\Scribe\Extracting\Strategies\UrlParameters\GetFromUrlParamTag;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Dingo\Api\Http\FormRequest as DingoFormRequest;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use Knuckles\Scribe\Extracting\FindsFormRequestForMethod;
use Knuckles\Scribe\Extracting\ParsesValidationRules;
use Knuckles\Scribe\Tools\ConsoleOutputUtils as c;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class GetFromFormRequestBase extends Strategy
{
    use ParsesValidationRules, FindsFormRequestForMethod;

    protected string $customParameterDataMethodName = '';

    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules): ?array
    {
        return $this->getParametersFromFormRequest($endpointData->method, $endpointData->route);
    }

    public function getParametersFromFormRequest(ReflectionFunctionAbstract $method, $route = null): array
    {
        if (!$formRequestReflectionClass = $this->getFormRequestReflectionClass($method)) {
            return [];
        }

        if (!$this->isFormRequestMeantForThisStrategy($formRequestReflectionClass)) {
            return [];
        }

        $className = $formRequestReflectionClass->getName();

        /**
         * Fix for dependency injection in form requests @N1ebieski
         *
         * Problem: when Form Request needs constructor parameters from Laravel IoC
         *
         * We can't just use $formRequest = App::make($className) because Laravel Form Requests use ValidatesWhenResolvedTrait
         * and automatically validate the request which will throw an exception.
         *
         * We also can't just catch an exception because we need a created form request object (but without automatic validation)
         * for getParametersFromValidationRules method.
         *
         * Therefore it is better to capture the created form request object before automatic validation and pass it by reference
         */
        /** @var LaravelFormRequest|DingoFormRequest $formRequest */
        $formRequest;

        App::resolving($className, function ($request) use (&$formRequest) {
            $formRequest = $request;
        });

        try {
            App::make($className);
        } catch (\Exception $e) {
            //
        }

        /**
         * Fix for route parameters in form requests @N1ebieski
         *
         * Problem: when logic in Form Request rules needs specific model from route binding
         */
        // Set the route properly so it works for users who have code that checks for the route.
        $formRequest->setRouteResolver(function () use ($formRequest, $route, $method) {
            // Also need to bind the request to the route in case their code tries to inspect current request
            $route->bind($formRequest);

            // Firstly we need to check if any route parameters are needed
            if (!$route->wheres || !is_array($route->wheres)) {
                return $route;
            }

            // We need to get an Example attribute from docblocks to know what id, slug or uuid to look for a model
            $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($route)['method'];
            $getFromUrlParamTag = new GetFromUrlParamTag($this->config);
            $docBlockParameters = $getFromUrlParamTag->getUrlParametersFromDocBlock($methodDocBlock->getTags());

            foreach ($route->wheres as $parameter => $type) {
                // We need to compare all controller method parameters to find those matching the route binding
                foreach ($method->getParameters() as $param) {
                    // Some parameters may be defined under a different name in the RouteServiceProvider (Route::bind)
                    // despite the fact that they point to the same model. For example: post and post_cache
                    if ($parameter !== $param->getName() && !Str::startsWith($parameter, $param->getName() . '_')) {
                        continue;
                    }

                    // Check if we have defined Example for this route parameter
                    if (
                        !isset($docBlockParameters[$param->getName()])
                        || !isset($docBlockParameters[$param->getName()]['example'])
                    ) {
                        continue;
                    }

                    $example = $docBlockParameters[$param->getName()]['example'];

                    // We don't set Route Binding if Example attribute is No-example
                    if (is_null($example)) {
                        continue;
                    }

                    // Full class name of the model matching the route parameter
                    $className = $param->getType()->getName();

                    // We use resolveRouteBinding() instead find() because we don't know if Example attribute is id, slug or uuid
                    $route->setParameter($parameter, $className::make()->resolveRouteBinding($example));
                }
            }

            return $route;
        });        
        
        $parametersFromFormRequest = $this->getParametersFromValidationRules(
            $this->getRouteValidationRules($formRequest),
            $this->getCustomParameterData($formRequest)
        );

        return $this->normaliseArrayAndObjectParameters($parametersFromFormRequest);
    }

    /**
     * @param LaravelFormRequest|DingoFormRequest $formRequest
     *
     * @return mixed
     */
    protected function getRouteValidationRules($formRequest)
    {
        if (method_exists($formRequest, 'validator')) {
            $validationFactory = app(ValidationFactory::class);

            return call_user_func_array([$formRequest, 'validator'], [$validationFactory])
                ->getRules();
        } else {
            return call_user_func_array([$formRequest, 'rules'], []);
        }
    }

    /**
     * @param LaravelFormRequest|DingoFormRequest $formRequest
     */
    protected function getCustomParameterData($formRequest)
    {
        if (method_exists($formRequest, $this->customParameterDataMethodName)) {
            return call_user_func_array([$formRequest, $this->customParameterDataMethodName], []);
        }

        c::warn("No {$this->customParameterDataMethodName}() method found in " . get_class($formRequest) . ". Scribe will only be able to extract basic information from the rules() method.");

        return [];
    }

    protected function getMissingCustomDataMessage($parameterName)
    {
        return "No data found for parameter '$parameterName' in your {$this->customParameterDataMethodName}() method. Add an entry for '$parameterName' so you can add a description and example.";
    }

    protected function isFormRequestMeantForThisStrategy(ReflectionClass $formRequestReflectionClass): bool
    {
        return $formRequestReflectionClass->hasMethod($this->customParameterDataMethodName);
    }

}

