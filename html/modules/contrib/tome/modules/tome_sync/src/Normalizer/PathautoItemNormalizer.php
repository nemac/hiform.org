<?php

namespace Drupal\tome_sync\Normalizer;

use Drupal\pathauto\PathautoState;

/**
 * Normalizer for Pathauto.
 *
 * @internal
 */
class PathautoItemNormalizer extends PathItemNormalizer {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = 'Drupal\pathauto\PathautoItem';

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []): array|string|int|float|bool|\ArrayObject|NULL {
    $values = parent::normalize($object, $format, $context);
    if (!in_array('pathauto', array_keys($object->getProperties()))) {
      return $values;
    }
    $value = $object->get('pathauto')->getValue();
    if ($value !== NULL) {
      $values['pathauto'] = $value;
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {
    if (!in_array('pathauto', array_keys($context['target_instance']->getProperties()))) {
      return parent::constructValue($data, $context);
    }
    // If the pathauto property is set to 1 and there is no pattern for this
    // entity, the default URL alias is not respected.
    if (!isset($data['pathauto']) || $data['pathauto'] === PathautoState::CREATE) {
      $data['pathauto'] = $context['target_instance']->get('pathauto')->getValue();
    }
    return parent::constructValue($data, $context);
  }

}
