<?php

namespace Drupal\Tests\wa\Functional;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * Tests query-level access enforcement for views on the wa table.
 *
 * View-level access settings are editable config and therefore one human
 * mistake away from exposing credential data. The wa_access query tag
 * (see wa_query_wa_access_alter()) must keep rows hidden from users
 * without the administrative permission no matter how a view's access is
 * configured.
 *
 * @group wa
 */
class PasskeyViewsAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['wa', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::configFactory()->getEditable('wa.settings')
      ->set('enable_passkey_login', TRUE)
      ->set('allowed_roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();
  }

  /**
   * Tests that rows stay hidden even when view access is misconfigured.
   */
  public function testPasskeysViewQueryLevelAccess(): void {
    $owner = $this->drupalCreateUser([]);
    $regular = $this->drupalCreateUser([]);
    $admin = $this->drupalCreateUser(['administer all user passkey']);

    // Seed a credential for the owner.
    $credentialId = 'cred-views-access';
    \Drupal::database()->insert('wa')
      ->fields([
        'uid' => $owner->id(),
        'credential_id' => $credentialId,
        'credential_hash' => hash('sha256', $credentialId),
        'public_key' => base64_encode('publickey'),
        'user_handle' => 'handle',
        'sign_counter' => 0,
        'transports' => json_encode([]),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'created' => \Drupal::time()->getRequestTime(),
        'last_used' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    // Admins see the credential row on the bundled view.
    $this->drupalLogin($admin);
    $this->drupalGet('admin/people/passkeys');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($owner->getAccountName());

    // Users without the permission are denied by the view's own access.
    $this->drupalLogin($regular);
    $this->drupalGet('admin/people/passkeys');
    $this->assertSession()->statusCodeEquals(403);

    // Simulate the human mistake: view access switched to "None".
    $view = View::load('wa_passkeys');
    $display = &$view->getDisplay('default');
    $display['display_options']['access'] = ['type' => 'none', 'options' => []];
    $view->save();
    // Push the changed route access into the shared routing table; outside
    // of tests this happens automatically at the end of the request.
    \Drupal::service('router.builder')->rebuild();

    // The route subscriber keeps enforcing the administrative permission,
    // so the page — including its exposed filter widgets — stays denied.
    $this->drupalGet('admin/people/passkeys');
    $this->assertSession()->statusCodeEquals(403);

    // Anonymous users are denied as well.
    $this->drupalLogout();
    $this->drupalGet('admin/people/passkeys');
    $this->assertSession()->statusCodeEquals(403);

    // Admins still see the rows through the misconfigured view.
    $this->drupalLogin($admin);
    $this->drupalGet('admin/people/passkeys');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($owner->getAccountName());

    // For displays without a route (blocks, embeds), the wa_access query
    // tag and the exposed filter stripping are the backstop. Execute the
    // view programmatically as the anonymous user.
    \Drupal::service('account_switcher')->switchTo(new AnonymousUserSession());
    $executable = Views::getView('wa_passkeys');
    $executable->setDisplay('default');
    $executable->preExecute();
    foreach ($executable->getHandlers('filter', 'default') as $filter) {
      $this->assertTrue(empty($filter['exposed']), 'Exposed filters are stripped for users without the permission.');
    }
    $executable->execute();
    $this->assertCount(0, $executable->result, 'The wa_access query tag hides all rows from users without the permission.');
    \Drupal::service('account_switcher')->switchBack();

    // The same programmatic execution returns rows for an administrator.
    \Drupal::service('account_switcher')->switchTo($admin);
    $executable = Views::getView('wa_passkeys');
    $executable->setDisplay('default');
    $executable->execute();
    $this->assertCount(1, $executable->result, 'Administrators keep seeing rows.');
    \Drupal::service('account_switcher')->switchBack();
  }

}
