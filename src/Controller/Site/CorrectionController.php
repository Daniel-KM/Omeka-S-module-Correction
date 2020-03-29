<?php
namespace Correction\Controller\Site;

use Correction\Api\Representation\CorrectionRepresentation;
use Correction\Form\CorrectionForm;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
// use Omeka\Form\ResourceForm;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class CorrectionController extends AbstractActionController
{
    public function editAction()
    {
        $api = $this->api();
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

        $resourceTypeMap = [
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }
        $resourceName = $resourceTypeMap[$resourceType];

        // Allow to check if the resource is public for the user.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $api
            ->searchOne($resourceName, ['id' => $resourceId])
            ->getContent();
        if (empty($resource)) {
            return $this->notFoundAction();
        }

        $settings = $this->settings();
        $user = $this->identity();

        $token = $this->checkToken($resource);
        if (!$token && !($user && $settings->get('correction_without_token'))) {
            return $this->viewError403();
        }

        if ($token) {
            $correction = $api
                ->searchOne('corrections', ['resource_id' => $resourceId, 'token_id' => $token->id()])
                ->getContent();
            $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
        } else {
            $correction = $api
                ->searchOne('corrections', ['resource_id' => $resourceId, 'email' => $user->getEmail(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                ->getContent();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        }

        /** @var \Correction\Form\CorrectionForm $form */
        $form = $this->getForm(CorrectionForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        $fields = $this->prepareFields($resource, $correction);

        $editable = $this->getEditableProperties($resource);
        if (!count($editable['corrigible']) && !count($editable['fillable'])) {
            $this->messenger()->addError('No metadata can be corrected. Ask the publisher for more information.'); // @translate
        } elseif ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'correct-resource-submit' => null]);
                $proposal = $this->prepareProposal($resource, $data);
                // The resource isn’t updated, but the proposition of correction
                // is saved for moderation.
                $response = null;
                if (empty($correction)) {
                    $data = [
                        'o:resource' => ['o:id' => $resourceId],
                        'o-module-correction:token' => $token ? ['o:id' => $token->id()] : null,
                        'o:email' => $token ? $token->email() : $user->getEmail(),
                        'o-module-correction:reviewed' => false,
                        'o-module-correction:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->create('corrections', $data);
                    if ($response) {
                        $this->messenger()->addSuccess('Corrections successfully submitted!'); // @translate
                    }
                } elseif ($proposal !== $correction->proposal()) {
                    $data = [
                        'o-module-correction:reviewed' => false,
                        'o-module-correction:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->update('corrections', $correction->id(), $data, [], ['isPartial' => true]);
                    if ($response) {
                        $this->messenger()->addSuccess('Corrections successfully submitted!'); // @translate
                    }
                } else {
                    $this->messenger()->addWarning('No change.'); // @translate
                    $response = true;
                }
                if ($response) {
                    $eventManager = $this->getEventManager();
                    $eventManager->trigger('correction.submit', $this, [
                        'correction' => $correction,
                        'resource' => $resource,
                        'data' => $data,
                    ]);
                    return $this->redirect()->toUrl($currentUrl);
                }
            } else {
                $this->messenger()->addError('An error occurred: check your input.'); // @translate
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'form' => $form,
            'resource' => $resource,
            'correction' => $correction,
            'fields' => $fields,
        ]);
    }

    /**
     * Get all fields that are updatable for this resource.
     *
     * The order is the one of the resource template, else the order of terms in
     * the database (Dublin Core first, bibo, foaf, then specific terms).
     *
     * Some corrections may not have the matching fields: it means that the
     * config changed, so the values are no more editable, so they are skipped.
     *
     * The output is similar than $resource->values(), but may contain empty
     * properties, and three more keys, corrigible, fillable, and corrections.
     *
     * <code>
     * array(
     *   {term} => array(
     *     'property' => {PropertyRepresentation},
     *     'alternate_label' => {label},
     *     'alternate_comment' => {comment},
     *     'corrigible' => {bool}
     *     'fillable' => {bool}
     *     'values' => array(
     *       {ValueRepresentation},
     *       {ValueRepresentation},
     *     ),
     *     'corrections' => array(
     *       array(
     *         'original' => array(
     *           'value' => {ValueRepresentation},
     *           'type' => {string},
     *           '@value' => {string},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ),
     *         'proposed' => array(
     *           'type' => {string},
     *           '@value' => {string},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ),
     *       ),
     *     ),
     *   ),
     * )
     * </code>
     *
     * @return array
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param CorrectionRepresentation $correction
     * @return array
     */
    protected function prepareFields(AbstractResourceEntityRepresentation $resource, CorrectionRepresentation $correction = null)
    {
        $fields = [];

        $editable = $this->getEditableProperties($resource);
        $resourceTemplate = $resource->resourceTemplate();
        $values = $resource->values();
        $defaultField = [
            'template_property' => null,
            'property' => null,
            'alternate_label' => null,
            'alternate_comment' => null,
            'corrigible' => false,
            'fillable' => false,
            'values' => [],
            'corrections' => [],
        ];

        // List the fields for the resource when there is a resource template.
        if ($resourceTemplate) {
            // List the resource template fields first.
            foreach ($resourceTemplate->resourceTemplateProperties() as $templateProperty) {
                $term = $templateProperty->property()->term();
                $fields[$term] = [
                    'template_property' => $templateProperty,
                    'property' => $templateProperty->property(),
                    'alternate_label' => $templateProperty->alternateLabel(),
                    'alternate_comment' => $templateProperty->alternateComment(),
                    'corrigible' => isset($editable['corrigible'][$term]),
                    'fillable' => isset($editable['fillable'][$term]),
                    'values' => isset($values[$term]['values']) ? $values[$term]['values'] : [],
                    'corrections' => [],
                ];
            }

            // When the resource template is configured, the remaining values
            // are never editable, since they are not in the resource template.
            if (!$editable['use_default']) {
                foreach ($values as $term => $valueInfo) {
                    if (!isset($fields[$term])) {
                        $fields[$term] = $valueInfo;
                        $fields[$term]['corrigible'] = false;
                        $fields[$term]['fillable'] = false;
                        $fields[$term]['corrections'] = [];
                        $fields[$term] = array_replace($defaultField, $fields[$term]);
                    }
                }
            }
        }

        // Append default fields from the main config, with or without template.
        if ($editable['use_default']) {
            $api = $this->api();
            // Append the values of the resource.
            foreach ($values as $term => $valueInfo) {
                if (!isset($fields[$term])) {
                    $fields[$term] = $valueInfo;
                    $fields[$term]['template_property'] = null;
                    $fields[$term]['corrigible'] = isset($editable['corrigible'][$term]);
                    $fields[$term]['fillable'] = isset($editable['fillable'][$term]);
                    $fields[$term]['corrections'] = [];
                    $fields[$term] = array_replace($defaultField, $fields[$term]);
                }
            }

            // Append the fillable fields.
            foreach ($editable['fillable'] as $term => $propertyId) {
                if (!isset($fields[$term])) {
                    $fields[$term] = [
                        'template_property' => null,
                        'property' => $api->read('properties', $propertyId)->getContent(),
                        'alternate_label' => null,
                        'alternate_comment' => null,
                        'corrigible' => isset($editable['corrigible'][$term]),
                        'fillable' => true,
                        'values' => [],
                        'corrections' => [],
                    ];
                }
            }
        }

        // Initialize corrections with existing values, then append corrections.
        foreach ($fields as $term => $field) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($field['values'] as $value) {
                // Method value() is label or value depending on type.
                $type = $value->type();
                if ($type === 'uri') {
                    $val = null;
                    $label = $value->value();
                } else {
                    $val = $value->value();
                    $label = null;
                }
                $fields[$term]['corrections'][] = [
                    'original' => [
                        'value' => $value,
                        'type' => $type,
                        '@value' => $val,
                        '@uri' => $value->uri(),
                        '@label' => $label,
                    ],
                    'proposed' => [
                        // The type cannot be changed.
                        'type' => $type,
                        '@value' => null,
                        '@uri' => null,
                        '@label' => null,
                    ],
                ];
            }
        }

        if (!$correction) {
            return $fields;
        }

        $proposals = $correction->proposal();

        // Clean old proposals.
        foreach ($proposals as $term => $termProposal) {
            foreach ($termProposal as $key => $proposal) {
                if (isset($proposal['proposed']['@uri'])) {
                    if (($proposal['original']['@uri'] === '' && $proposal['proposed']['@uri'] === '')
                        && ($proposal['original']['@label'] === '' && $proposal['proposed']['@label'] === '')
                    ) {
                        unset($proposals[$term][$key]);
                    }
                } else {
                    if ($proposal['original']['@value'] === '' && $proposal['proposed']['@value'] === '') {
                        unset($proposals[$term][$key]);
                    }
                }
            }
        }
        $proposals = array_filter($proposals);
        if (!$proposals) {
            return $fields;
        }

        // Fill the proposed corrections, according to the original value.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['corrections'] as &$fieldCorrection) {
                $proposed = null;
                $type = $fieldCorrection['original']['type'];
                if ($type === 'uri') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@uri'])
                            && $proposal['original']['@uri'] === $fieldCorrection['original']['@uri']
                            && $proposal['original']['@label'] === $fieldCorrection['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldCorrection['proposed'] = [
                        'type' => $type,
                        '@value' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif (strtok($type, ':') === 'resource' || $type !== 'literal') {
                    // TODO Value resource are currently not editable.
                    continue;
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@value'])
                            && $proposal['original']['@value'] === $fieldCorrection['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldCorrection['proposed'] = [
                        'type' => $type,
                        '@value' => $proposed['@value'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldCorrection);

        // Fill the proposed correction, according to the existing values: some
        // corrections may have been accepted or the resource updated, so check
        // if there are remaining corrections that were validated.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['corrections'] as &$fieldCorrection) {
                $proposed = null;
                $type = $fieldCorrection['original']['type'];
                if ($type === 'uri') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@uri'])
                            && $proposal['proposed']['@uri'] === $fieldCorrection['original']['@uri']
                            && $proposal['proposed']['@label'] === $fieldCorrection['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldCorrection['proposed'] = [
                        'type' => $type,
                        '@value' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif (strtok($type, ':') === 'resource' || $type !== 'literal') {
                    // TODO Value resource are currently not editable.
                    continue;
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@value'])
                            && $proposal['proposed']['@value'] === $fieldCorrection['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldCorrection['proposed'] = [
                        'type' => $type,
                        '@value' => $proposed['@value'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldCorrection);

        // Append only remaining corrections that are fillable.
        // Other ones are related to an older config.
        $proposals = array_intersect_key(array_filter($proposals), $editable['fillable']);
        foreach ($proposals as $term => $termProposal) {
            foreach ($termProposal as $proposal) {
                if (isset($proposal['proposed']['@uri'])) {
                    $fields[$term]['corrections'][] = [
                        'original' => [
                            'value' => null,
                            'type' => 'uri',
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            'type' => 'uri',
                            '@value' => null,
                            '@uri' => $proposal['proposed']['@uri'],
                            '@label' => $proposal['proposed']['@label'],
                        ],
                    ];
                } else {
                    $fields[$term]['corrections'][] = [
                        'original' => [
                            'value' => null,
                            'type' => 'literal',
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            'type' => 'literal',
                            '@value' => $proposal['proposed']['@value'],
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * Prepare the proposal for saving.
     *
     * The form and this method must use the same keys.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $proposal
     * @return array
     */
    protected function prepareProposal(AbstractResourceEntityRepresentation $resource, array $proposal)
    {
        // Clean data.
        foreach ($proposal as &$values) {
            // Manage specific posts.
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $value['@value'] = trim($value['@value']);
                }
                if (isset($value['@uri'])) {
                    $value['@uri'] = trim($value['@uri']);
                }
                if (isset($value['@label'])) {
                    $value['@label'] = trim($value['@label']);
                }
            }
        }
        unset($values, $value);

        // Filter data.
        $editable = $this->getEditableProperties($resource);
        $corrigible = $editable['corrigible'];
        $fillable = $editable['fillable'];
        $proposalCorrigible = array_intersect_key($proposal, $corrigible);
        $result = [];
        foreach (array_keys($corrigible) as $term) {
            // TODO Manage all types of data, in particular custom vocab and value suggest.
            /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
            $values = $resource->value($term, [/*'type' => 'literal',*/ 'all' => true, 'default' => []]);

            $proposedValues = isset($proposalCorrigible[$term]) ? $proposalCorrigible[$term] : [];
            // Don't save corrigible and fillable twice.
            unset($fillable[$term]);

            // First, save original values (literal only) and the matching corrections.
            // TODO Check $key and order of values.
            $key = 0;
            foreach ($values as $value) {
                if (!isset($proposedValues[$key])) {
                    continue;
                }

                if ($value->type() != "literal" && $value->type() != "uri") {
                    continue;
                }

                if ($value->type() == 'literal') {
                    $result[$term][] = [
                        'original' => ['@value' => $value->value()],
                        'proposed' => $proposedValues[$key],
                    ];
                } elseif ($value->type() == 'uri') {
                    $result[$term][] = [
                        'original' => ['@label' => $value->value(), '@uri' => $value->uri()],
                        'proposed' => $proposedValues[$key],
                    ];
                }
                // Remove the proposed value from the list of proposed values in order to keep only new corrections to append.
                unset($proposedValues[$key]);
                ++$key;
            }

            // Second, save remaining corrections (no more original or appended).
            foreach ($proposedValues as $proposedValue) {
                if ($proposedValue === '') {
                    continue;
                }
                if (array_key_exists("@uri", $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@uri' => '','@label'=>''],
                        'proposed' => $proposedValue,
                    ];
                } elseif (array_key_exists("@value", $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@value' => ''],
                        'proposed' => $proposedValue,
                    ];
                }
            }
        }

        // Third, save remaining fillable properties.
        $proposalFillable = array_intersect_key($proposal, $fillable);
        foreach (array_keys($fillable) as $term) {
            if (!isset($proposalFillable[$term])) {
                continue;
            }
            foreach ($proposalFillable[$term] as $proposedValue) {
                if ($proposedValue === '') {
                    continue;
                }
                if (array_key_exists('@uri', $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@uri' => '', '@label'=>''],
                        'proposed' => $proposedValue,
                    ];
                } elseif (array_key_exists('@value', $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@value' => ''],
                        'proposed' => $proposedValue,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Get the list of editable property ids by terms.
     *
     *  The list come from the resource template if it is configured, else the
     *  default list is used.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return array
     */
    protected function getEditableProperties(AbstractResourceEntityRepresentation $resource)
    {
        $result = [
            'use_default' => false,
            'corrigible' => [],
            'fillable' => [],
        ];

        $propertyIdsByTerms = $this->propertyIdsByTerms();

        $resourceTemplate = $resource->resourceTemplate();
        if ($resourceTemplate) {
            $correctionPartMap = $this->resourceTemplateCorrectionPartMap($resourceTemplate->id());
            $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
            $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
        }

        $result['use_default'] = !count($result['corrigible']) && !count($result['fillable']);
        if ($result['use_default']) {
            $settings = $this->settings();
            $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_corrigible', [])));
            $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_fillable', [])));
        }

        return $result;
    }

    /**
     * Helper to return a message of error as normal view.
     *
     * @return \Zend\View\Model\ViewModel
     */
    protected function viewError403()
    {
        // TODO Return a normal page instead of an exception.
        // throw new \Omeka\Api\Exception\PermissionDeniedException('Forbidden access.');
        $message = 'Forbidden access.'; // @translate
        $this->getResponse()
            ->setStatusCode(\Zend\Http\Response::STATUS_CODE_403);
        $view = new ViewModel;
        return $view
            ->setTemplate('error/403')
            ->setVariable('message', $message);
    }
}
