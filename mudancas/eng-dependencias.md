---
tipo: seguranca
---
## Changelog
- Dependências atualizadas, zerando os alertas do `composer audit` (eram 61): Symfony 7.4.21
  (entre eles o bypass do `register_argc_argv` corrigido na origem), Twig 3.30, PhpSpreadsheet
  2.4.8, Dompdf 3.1.6, PhpWord 1.4 com PhpOffice Math 0.3, Guzzle 7.15.5 e mcp/sdk 0.7.1.
- Na suíte de testes, a página de erro é a de produção (com as mensagens da exceção num
  comentário): a de depuração do Symfony 7.4.21 mostrava o código do próprio teste e gerava falso
  vazamento nos testes de isolamento entre escritórios.

## Para o cliente
- Atualizações de segurança nos componentes do sistema.
