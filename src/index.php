<?php

namespace Patrn1\NovaFieldsHelpers;

use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Http\Requests\NovaRequest;
use Outl1ne\MultiselectField\Multiselect;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Select;
use Chaseconey\ExternalImage\ExternalImage;
use ZiffMedia\NovaSelectPlus\SelectPlus;
use Armincms\Fields\BelongsToMany;
use Laravel\Nova\Resource;
use Illuminate\Support\Collection;

function get_autocomplete_field(string $name, string $attribute, $configure = null) {

    $oldValueAttrName = "{$attribute}_old";

    $formField = Text::make($name, $attribute);

    $configure = $configure ?: fn() => $formField;

    $oldValueField = Hidden::make($name, $oldValueAttrName)

        ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {

        });

    list($formField, $dependencyList, $dependencyHandler) = $configure( $formField );

    if (!empty($dependencyList) && isset($dependencyHandler)) {

        $formField->dependsOn([$oldValueAttrName], function(Text $field, NovaRequest $request, FormData $formData) use ($oldValueAttrName, $attribute) {

            $values = @json_decode($request->get($oldValueAttrName), true) ?? [];

            $oldValue = $values["old"] ?? null;

            // менять значение у поля с автозаполнением, толькое если поле уже не было изменено вручную
            if (empty($formData->{$attribute}) || ($oldValue === $formData->{$attribute})) {

                $field->value = $values["new"] ?? '';

            }

        });

        $oldValueField->dependsOn($dependencyList, function(Text $field, NovaRequest $request, FormData $formData) use ($oldValueAttrName, $dependencyHandler) {

            $oldValue = @json_decode($formData->{$oldValueAttrName}, true)["new"] ?? "";

            $dependencyHandler($field, $request, $formData);

            $field->value = json_encode([
                "old" => $oldValue,
                "new" => $field->value,
            ]);

        });

    }

    return [ $formField, $oldValueField ];

}

function get_belongstomany_field(string $name, string $attribute, string $modelClass, $configure = null) {

    $formField = BelongsToMany::make($name, $attribute, $modelClass);

    $configure = $configure ?: fn() => $formField;

    return $configure( $formField );

}

function get_belongstomany_api_field(string $name, string $attribute, string $modelClass, $configure = null) {

    $configure = $configure ?: fn() => null;

    $formField = \ZiffMedia\NovaSelectPlus\SelectPlus::make($name, $attribute, $modelClass)
        ->showOnCreating()
        ->showOnUpdating()
        ->hideFromIndex();

    $displayField = \Armincms\Fields\BelongsToMany::make($name, $attribute, $modelClass)
        ->exceptOnForms()
        ->showOnDetail()
        ->hideFromIndex();

    $formField = $configure( $formField );

    return [

        $formField,

        $displayField,
    ];
}

function get_relationship_field(string $name, string $labelAttribute, string $relationshipAttribute, string $novaResource, ?string $searchAtrribute = null) {

    $model = $novaResource::$model;

    $sp = SelectPlus::make($name, $relationshipAttribute, $novaResource, $labelAttribute);

    $sp->usingDetailLabel($labelAttribute);
    $sp->usingIndexLabel($labelAttribute);

    $searchAtrribute = $searchAtrribute ?? $labelAttribute;

    $sp->ajaxSearchable(function ($search) use ($model, $labelAttribute, $searchAtrribute) {
        return $model::where($searchAtrribute, 'LIKE', "%{$search}%")->limit(5);
    });

    return $sp;
}

function get_raw_password_field($name, $modelAttribute) {

    return Password::make($name, $modelAttribute)

        ->placeholder('mypassword123')

        ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {

            $model->{$attribute} = $request->input($requestAttribute);
        });
}

function get_resource_field($resource, $modelAttribute) {

    $request = new \Laravel\Nova\Http\Requests\NovaRequest;

    if (empty($request->resource)) {

        $request->resource = new $resource::$model;

    }

    $fields = $resource->fields($request);

    foreach ($fields as $field) {

        $subFieldList = $field->data ?? [];

        $subFieldList[] = $field;

        foreach ($subFieldList as $subField) {
            if ($subField->attribute === $modelAttribute) return $subField;
        }
    }

    return null;
}

function get_standalone_search_field($resource, $name, $modelAttribute, $url, $params = []) {

    return current( get_search_field($resource, $name, $modelAttribute, $url, $params) );

}


function get_search_field($resource, $name, $modelAttribute, $url, $params = []) {

    $model = $resource->resource;

    $dependsOn = $params['dependsOn'] ?? [];

    $configure = $params['configure'] ?? fn($field) => $field;

    $titleAttribute = $params['titleAttribute'] ?? 'name';

    $attributeOrEntity = $model->{$modelAttribute};

    // $isRelationship = $attributeOrEntity && method_exists($attributeOrEntity, 'getKeyName'); getForeignKeyName
    $isRelationship = method_exists($model, $modelAttribute);

    if ($isRelationship) {

        $attribute = $model->{$modelAttribute}()->getForeignKeyName();

    } else {

        $attribute = $modelAttribute;

    }

    $attributeValue = $model->{$attribute};

    $searchAtrributeGetter = fn($attr) => "{$attr}_search";

    $searchAtrribute = $searchAtrributeGetter( $attribute );

    $dependsOn = array_map(fn($dependency) => $searchAtrributeGetter( $dependency ), $dependsOn);

    $valueFieldDependencies = [ $searchAtrribute, ...$dependsOn ];

    $searchFieldDependencies = count($dependsOn) ? [ $attribute ] : [];

    // dd([ $attribute, $attributeValue ]);

    $valueField = Hidden::make($name, $attribute)

        ->default($model->{$attribute})

        // ->onlyOnForms()

        ->dependsOn(

            ...get_field_dependencies($valueFieldDependencies)

        )
    ;

    if ($isRelationship) {

        // $optionsGetter = get_select_options($attributeOrEntity, NULL, 'id', [$titleAttribute, 'id']);

        // dd([ $attributeValue, $optionsGetter ]);

        // $placeholder = $optionsGetter[ $attributeValue ];
        // $placeholder = $optionsGetter[ $attributeValue ] ?? NULL;

        $attributeDisplayedValue = tree_get_path_value($model, "{$modelAttribute}.{$titleAttribute}");


        $optionsGetter = [$attributeValue => $attributeDisplayedValue];

        $placeholder = $attributeDisplayedValue;

    } else {

        $optionsGetter = [$attributeValue => $attributeValue];

        $placeholder = $attributeValue;

    }

    $placeholder = $placeholder  ?: $params['placeholder'] ?? '';

    $searchField = Multiselect::make($name, $searchAtrribute)

        ->placeholder($placeholder)

        #->exceptOnIndex()

        # Нужно для отображения во вьюхах
        ->options($optionsGetter)

        ->optionsLimit(100)

        # Нужно для отображения во вьюхах
        ->withMeta([ 'value' => $attributeValue ])

        ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {

        })

        ->dependsOn(

            $searchFieldDependencies,

            function (Multiselect $field, NovaRequest $request, FormData $formData) use ($attribute, $dependsOn) {

                if ($request->method() === 'GET') return;

                $newFieldValue = $fieldUpdateString = $request->input($attribute);

                $field->placeholder($newFieldValue);

            }
        )

        ->displayUsing(fn($valueJson) => @json_decode($valueJson)[$attribute] ?? null)

        ->api($url, $resource::class);

    $searchField = $configure( $searchField );

    return [ $searchField, $valueField  ];

}

function get_new_field_value(NovaRequest $request, $attribute, array $dependencyList) {

    if ($request->method() === 'GET') return null;

    $updatedFieldList = array_intersect($dependencyList, array_keys($request->all()));

    foreach ($updatedFieldList as $updatedField) {

        $fieldUpdateString = $request->input($updatedField);

        if (preg_match('|_search$|', $updatedField)) {

            if ($fieldUpdateString === '[]') continue;

        }

        try {

            $fieldUpdate = json_decode($fieldUpdateString);

        } catch(\Throwable) {

            $fieldUpdate = $fieldUpdateString;

        }

        // if (empty($fieldUpdate)) continue;

        // // if (!is_object($fieldUpdate)) continue;

        if (is_object($fieldUpdate)) {

            $newFieldValue = $fieldUpdate->{$attribute};

        } else {

            $newFieldValue = $fieldUpdate;

        }

        // // if (empty($newFieldValue)) continue;

        // if (!in_array($attribute, [ 'name', 'inn', 'kpp' ])) {

        //     dd(['$attribute' => $attribute, '$fieldUpdate' => $fieldUpdate]);

        // }

        return $newFieldValue;
    }

    return null;
}

function set_new_field_value(Field &$field, NovaRequest &$request, array $dependencyList) {

    $attribute = $field->attribute;

    $newFieldValue = get_new_field_value($request, $attribute, $dependencyList);

    if (!is_null($newFieldValue)) {

        // $formData->{$attribute} = $newFieldValue;

        $field->setValue($newFieldValue);
    }
}

function get_field_dependencies($searchFieldList) {

    return [

        $searchFieldList,

        fn($field, $request) => set_new_field_value($field, $request, $searchFieldList)
    ];
}

function get_cb_field(string $label, string $attribute, $configure = null) {

    $field = Boolean::make($label, $attribute)->onlyOnForms();

    $configure = $configure ?: fn() => null;

    $configure($field);

    return [

        ExternalImage::make($label, $attribute)

            ->resolveUsing(function($value, $resource, $attribute) {

                $basePath = '/icons/';

                if ($resource->{$attribute}) {

                    return $basePath . 'checkbox-checked.svg';

                } else {

                    return $basePath . 'checkbox-empty.svg';

                }
            })
            ->width(15)
            ->height(23)
            ->exceptOnForms(),

        $field,
    ];
}

function get_select_field($request, string $label, string $attribute, $configure = null) {

    $valueModel = get_resource_model($request);

//    $value = tree_get_path_value($valueModel, $path);

    // if (is_object($value)) return Text::make($label, fn() => $value)->exceptOnForms();

    // preg_match('#^[^.]+#', $path, $pathMatches);

    // $attribute = current($pathMatches);

    $field = Select::make($label, $attribute);

    // $field->hideFromIndex();

    $configure = $configure ?: fn() => null;

    return $configure($field) ?? $field;

}

function get_select_options($searchModel, $request, $attribute, $pluckOptions) {

    $valueModel = get_resource_model($request);

    $options = [];

    if (!$searchModel) return $options;

    #if ($request?->isResourceDetailRequest() || $request?->isResourcePreviewRequest()) {

    if (!$request?->isFormRequest()) {

        $attributeValue = $valueModel->{$attribute};

        $options = $searchModel::where($pluckOptions[1], $attributeValue)->limit(1);

    } else {

        // dd($model);

        $options = $searchModel::query();

    }

    // if (empty($model->id)) dd($model);

    // $options = $model::all();

    // return [
    //     "resources" => $options->map(fn($client) => [
    //         'display' => $client->name,
    //         'value' => $client->id,

    //         // 'display' => $client->${$pluckOptions[0]},
    //         // 'value' => $client->${$pluckOptions[1]},
    //     ]),
    // ];
    // return $options->map(fn($client) => [
    //     json_encode(['client_id' => $client->id]) => $client->name,
    //     // 'display' => $client->${$pluckOptions[0]},
    //     // 'value' => $client->${$pluckOptions[1]},
    // ]);

    if (empty($pluckOptions)) return $options->get()->toArray();

    if (Schema::hasColumn($searchModel::getTableName(), $pluckOptions[0])) {

        return $options->get()->pluck(...$pluckOptions)->toArray();

    }

    return $options->get()->pluck(...$pluckOptions)->toArray();
}

function get_resource_model(NovaRequest $request, Resource $resource = null) {

    if ($request->resource() && $resource) {

        if ($request->resource() !== $resource::class) return null;

    }

    return is_subclass_of($request->resource, '\Illuminate\Database\Eloquent\Model') ? $request->resource : $request->findModel($request->resourceId);

}

function set_readonly_field($field) {

    if (isset($field->repeatables)) {
        
        if ($field->repeatables instanceof Collection) {
    
            $field->repeatables->each(fn($rpt) => set_readonly_field($rpt));
    
        }
    }

    if (isset($field->fields)) {

        if ($field->fields instanceof Collection) {
    
            $field->fields->each(fn($fld) => set_readonly_field($fld));
    
        }

    }

    $field->withMeta([
        'extraAttributes' => [
            'readonly' => true,
        ],
    ]);

    if (method_exists($field, 'fillUsing')) {

        $field->fillUsing(function () { });

    }
    
    return $field;

}
