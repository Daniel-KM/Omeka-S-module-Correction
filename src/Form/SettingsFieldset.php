<?php
namespace Correction\Form;

use Omeka\Form\Element\PropertySelect;
use Omeka\Form\Element\ResourceTemplateSelect;
use Zend\Form\Element;
use Zend\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Correction'; // @translate

    public function init()
    {
        $this
            ->add([
                'name' => 'correction_template_editable',
                'type' => ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Template to use for default edit form', // @translate
                    'info' => 'This template is used only when the current resource has no template or a template without config. If not set, the properties below will be used.', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'correction_template_editable',
                    'multiple' => false,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a resource template…', // @translate
                ],
            ])
            ->add([
                'name' => 'correction_properties_corrigible',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties to correct when no template is available', // @translate
                    'info' => 'Only the selected properties will be proposed for public correction. This list is used only when the resource template is not configured.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'correction_properties_corrigible',
                    'multiple' => true,
                    // Should be true and without filter, but simpler for user.
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'correction_properties_fillable',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties to fill when no template is available', // @translate
                    'info' => 'Allow user to append new values for the selected properties. This list is used only when the resource template is not configured.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'correction_properties_fillable',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'correction_without_token',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Allow correction of resources without a token', // @translate
                ],
                'attributes' => [
                    'id' => 'correction_without_token',
                ],
            ])
            ->add([
                'name' => 'correction_token_duration',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Days for token to expire', // @translate
                    'info' => 'Allow to set the default expiration date of a token. Let empty to remove expiration.', // @translate
                ],
                'attributes' => [
                    'id' => 'correction_token_duration',
                    'min'  => '0',
                    'step' => '1',
                    'data-placeholder' => '90', // @translate
                ],
            ])
        ;
    }
}
