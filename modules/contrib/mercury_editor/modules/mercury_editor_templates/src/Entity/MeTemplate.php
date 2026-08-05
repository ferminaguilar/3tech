<?php

namespace Drupal\mercury_editor_templates\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\mercury_editor_templates\MeTemplateInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Mercury Editor template entity class.
 *
 * @ContentEntityType(
 *   id = "me_template",
 *   label = @Translation("Mercury Editor Template"),
 *   label_collection = @Translation("Mercury Editor Templates"),
 *   label_singular = @Translation("Mercury Editor template"),
 *   label_plural = @Translation("Mercury Editor templates"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Mercury Editor templates",
 *     plural = "@count Mercury Editor templates",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\mercury_editor_templates\MeTemplateListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\mercury_editor_templates\MeTemplateAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\mercury_editor_templates\Entity\MeTemplateForm",
 *       "edit" = "Drupal\mercury_editor_templates\Entity\MeTemplateForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     }
 *   },
 *   base_table = "me_template",
 *   data_table = "me_template_field_data",
 *   revision_table = "me_template_revision",
 *   revision_data_table = "me_template_field_revision",
 *   show_revision_ui = FALSE,
 *   admin_permission = "access mercury editor template overview",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_uid",
 *     "revision_created" = "revision_timestamp",
 *     "revision_log_message" = "revision_log"
 *   },
 *   links = {
 *     "collection" = "/admin/content/me-template",
 *     "add-form" = "/me-template/add",
 *     "canonical" = "/me-template/{me_template}",
 *     "edit-form" = "/me-template/{me_template}/edit",
 *     "delete-form" = "/me-template/{me_template}/delete",
 *   },
 *   field_ui_base_route = "entity.me_template.settings",
 * )
 */
class MeTemplate extends ContentEntityBase implements MeTemplateInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Template Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Template Description'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the Mercury Editor template was created.'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the Mercury Editor template was last edited.'));

    $fields['content'] = BaseFieldDefinition::create('entity_reference_revisions')
      ->setLabel(t('Template Content'))
      // ->setDescription(t('This component will be available when creating '))
      ->setSetting('target_type', 'paragraph')
      ->setCardinality(-1)
      ->setSetting('handler', 'default:paragraph')
      ->setSetting('handler_settings', [
        'target_bundles' => NULL,
        'negate' => TRUE,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'layout_paragraphs',
        'weight' => 100,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'layout_paragraphs',
      ]);

    return $fields;
  }

}
