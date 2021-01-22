<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\ORM\Annotation;

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * @since 1.0.0
 * @Annotation
 * @Target({"PROPERTY"})
 * @since 1.0.0
 */
class FieldOptions {

    /**
     * @since 1.0.0
     * @var ?string $displayName Message ID for the display name. If null, it is automatically generated as the property name field the prefix "field.".
     */
    public ?string $displayName = null;

    /**
     * @since 1.0.0
     * @var array $displayNameParams Array of parameters used by the translator for the display name.
     *  By default, the 'count' parameter is set to 1 to make it easier when wanting the singular form
     * when multiple forms are specified in the locale file without having to manually specify it.
     */
    public array $displayNameParams = ['count' => 1];

    /**
     * Tooltip to show with an informational icon next to the field
     * @since 1.0.0
     * @var string
     */
    public string $info_tooltip;

    /**
     * Set the visibility of this field on forms. Does not affect if the field can be changed when visible.
     *
     * If set to hidden, the field is included in the form but hidden.
     * If set to secure, the field is included in the form but as a password.
     * If set to excluded, the field is not added to the form at all.
     * If set to view_only, the field is only visible when viewing items (Not in new item form).
     * All other values will make the field show in the form as usual.
     * For fields that don't include the Column annotation, this does nothing since the field would not be included anyways.
     *
     * @since 1.0.0
     * @var string $form_visibility
     */
    public string $form_visibility = 'show';

    /**
     * Set the visibility of this field on search results.
     * If set to hidden, the field is included in the data returned by the Search engine but hidden.
     *    (Only useful if there is some pre-processing that need it. Still excluded from results).
     * If set to excluded, the field is not added to the search result at all.
     * All other values will make the field show in the search as usual.
     * For fields that don't include the Column annotation, this does nothing since the field would not be included anyways.
     *
     * @since 1.0.0
     * @var string $search_visibility
     */
    public string $search_visibility = 'show';

    /**
     * Set if the field is readonly (Global option)
     *
     * @since 1.0.0
     * @var boolean
     */
    public bool $readonly = false;

    /**
     * Indicates if, in the list view, this field should act as a link to the item's view/edit page.
     *
     * @since 1.0.0
     * @var bool
     */
    public bool $self_itemlink = false;

    /**
     * Specify the type of form field that should be used.
     * If null, it is automatically determined based on the Doctrine type.
     *
     * @since 1.0.0
     * @var ?string
     */
    public ?string $field_type = null;
}
