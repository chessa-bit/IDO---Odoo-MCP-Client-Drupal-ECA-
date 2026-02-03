<?php

declare(strict_types=1);

namespace Drupal\eca_mcp_bridge\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends LLM output to a local MCP server.
 *
 * @Action(
 *   id = "eca_mcp_bridge_action",
 *   label = @Translation("MCP bridge (OpenAI → MCP server)"),
 *   type = "system"
 * )
 */
final class McpBridgeAction extends ActionBase implements ContainerFactoryPluginInterface {
  /**
   * The HTTP client.
   */
  private ClientInterface $httpClient;

  /**
   * The token service.
   */
  private Token $token;

  /**
   * The logger channel.
   */
  private $logger;

  /**
   * Constructs the action.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $httpClient, Token $token, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $httpClient;
    $this->token = $token;
    $this->logger = $logger_factory->get('eca_mcp_bridge');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('token'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'openai_endpoint' => 'https://api.openai.com/v1/chat/completions',
      'openai_api_key' => '',
      'openai_model' => 'gpt-4o-mini',
      'openai_system_prompt' => 'Sei un assistente che prepara input per un server MCP locale.',
      'openai_user_prompt' => 'Usa i dati: [context]',
      'openai_temperature' => '0.2',
      'openai_max_tokens' => '512',
      'mcp_url' => 'http://127.0.0.1:3333',
      'mcp_method' => 'tools/call',
      'mcp_headers' => '{"Content-Type":"application/json"}',
      'mcp_body' => '{"jsonrpc":"2.0","id":"[random_uuid]","method":"tools/call","params":{"name":"my_tool","arguments":{"input":"[openai_response]"}}}',
      'timeout' => '20',
      'verify_ssl' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['openai_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI endpoint'),
      '#default_value' => $this->configuration['openai_endpoint'],
      '#description' => $this->t('Endpoint to call for chat completions.'),
      '#required' => TRUE,
    ];
    $form['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API key'),
      '#default_value' => $this->configuration['openai_api_key'],
      '#description' => $this->t('Provide the API key or use tokens to inject it at runtime.'),
    ];
    $form['openai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI model'),
      '#default_value' => $this->configuration['openai_model'],
      '#required' => TRUE,
    ];
    $form['openai_system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $this->configuration['openai_system_prompt'],
      '#description' => $this->t('Supports tokens from the ECA context.'),
    ];
    $form['openai_user_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('User prompt'),
      '#default_value' => $this->configuration['openai_user_prompt'],
      '#description' => $this->t('Supports tokens from the ECA context. Use [context] or other tokens.'),
    ];
    $form['openai_temperature'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Temperature'),
      '#default_value' => $this->configuration['openai_temperature'],
    ];
    $form['openai_max_tokens'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Max tokens'),
      '#default_value' => $this->configuration['openai_max_tokens'],
    ];
    $form['mcp_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('MCP server URL'),
      '#default_value' => $this->configuration['mcp_url'],
      '#description' => $this->t('Local MCP server base URL or full endpoint.'),
      '#required' => TRUE,
    ];
    $form['mcp_method'] = [
      '#type' => 'textfield',
      '#title' => $this->t('MCP method'),
      '#default_value' => $this->configuration['mcp_method'],
      '#description' => $this->t('Used to build the default request body.'),
    ];
    $form['mcp_headers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('MCP headers (JSON)'),
      '#default_value' => $this->configuration['mcp_headers'],
      '#description' => $this->t('JSON object of headers. Supports tokens.'),
    ];
    $form['mcp_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('MCP request body (JSON template)'),
      '#default_value' => $this->configuration['mcp_body'],
      '#description' => $this->t('JSON template. Supports tokens like [openai_response], [context], [random_uuid].'),
    ];
    $form['timeout'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timeout (seconds)'),
      '#default_value' => $this->configuration['timeout'],
    ];
    $form['verify_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify SSL certificates'),
      '#default_value' => $this->configuration['verify_ssl'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach (array_keys($this->defaultConfiguration()) as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL, array $context = []): void {
    $token_data = $context;
    if ($entity) {
      $token_data['entity'] = $entity;
    }

    $random_uuid = $this->randomUuid();
    $system_prompt = $this->token->replace($this->configuration['openai_system_prompt'], $token_data);
    $user_prompt = $this->token->replace($this->configuration['openai_user_prompt'], $token_data);
    $api_key = $this->token->replace($this->configuration['openai_api_key'], $token_data);

    $openai_payload = [
      'model' => $this->configuration['openai_model'],
      'temperature' => (float) $this->configuration['openai_temperature'],
      'max_tokens' => (int) $this->configuration['openai_max_tokens'],
      'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $user_prompt],
      ],
    ];

    $openai_headers = [
      'Authorization' => 'Bearer ' . $api_key,
      'Content-Type' => 'application/json',
    ];

    $openai_response_text = '';
    if (!empty($api_key)) {
      $response = $this->httpClient->post($this->configuration['openai_endpoint'], [
        'headers' => $openai_headers,
        'json' => $openai_payload,
        'timeout' => (float) $this->configuration['timeout'],
        'verify' => (bool) $this->configuration['verify_ssl'],
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
      $openai_response_text = $body['choices'][0]['message']['content'] ?? '';
    }
    else {
      $this->logger->warning('OpenAI API key is empty; skipping LLM call.');
    }

    $mcp_body_template = $this->configuration['mcp_body'];
    $token_data['openai_response'] = $openai_response_text;
    $token_data['random_uuid'] = $random_uuid;

    $mcp_body_json = $this->token->replace($mcp_body_template, $token_data);
    $mcp_headers_raw = $this->token->replace($this->configuration['mcp_headers'], $token_data);

    $mcp_headers = json_decode($mcp_headers_raw, TRUE);
    if (!is_array($mcp_headers)) {
      $mcp_headers = ['Content-Type' => 'application/json'];
      $this->logger->warning('Invalid MCP headers JSON provided; falling back to Content-Type header.');
    }

    $mcp_body = json_decode($mcp_body_json, TRUE);
    if (!is_array($mcp_body)) {
      $this->logger->error('Invalid MCP body JSON. Skipping MCP call.');
      return;
    }

    $this->httpClient->post($this->configuration['mcp_url'], [
      'headers' => $mcp_headers,
      'json' => $mcp_body,
      'timeout' => (float) $this->configuration['timeout'],
      'verify' => (bool) $this->configuration['verify_ssl'],
    ]);
  }

  /**
   * Generates a random UUID.
   */
  private function randomUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

}
