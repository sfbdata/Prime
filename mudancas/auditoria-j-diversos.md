---
tipo: correcao
---
## Changelog
- MCP, uploads, webhook da Cora e retenção da IA (auditoria de segurança, lote J: 1.10, 3.13, 3.16; 2ª rodada: 1.18, 1.19):
  - 1.10: `ConexaoLeitura::consultar()` roda cada consulta numa transação própria, somente leitura, com `SET LOCAL statement_timeout` reaplicado e `ROLLBACK` no fim. Um `set_config` feito numa consulta não desliga mais o teto nem o modo somente leitura da seguinte. O runbook do MCP ganha `temp_file_limit`, `CONNECTION LIMIT` e o comando de conferência da role.
  - 3.13: o nginx de produção não monta mais o volume `uploads_prod` (php, worker e scheduler continuam montando). Todo `/uploads/` já ia ao PHP; agora um erro na regra do nginx não entrega arquivo. Teste novo trava a regra do nginx, o compose e a cópia para o static.
  - 3.16: limitador `webhook_cora` (60 por minuto por IP; acima disso, 200 sem gravar nem enfileirar) e só uma reconsulta pendente por recurso no último minuto (`NotificacaoRecebidaRepository::haPendenteDoRecurso`), sem migration.
  - 1.18: o `audit_log` deixa de guardar o texto da `AnaliseDeInteligencia` (resumo, pontos, quem age, texto da análise, texto bruto e as versões editadas). A migration `Version20261105120000` apaga essas chaves das linhas antigas.
  - 1.19: `app:inteligencia:expirar-conteudo` apaga também as falas do Chat I.A mais antigas que o prazo, os anexos dessas falas (o arquivo sai depois do COMMIT) e as conversas que ficam vazias, em todos os escritórios (`RetencaoDoChatIa`, `ExpiracaoDoChatIa`).

## Para o cliente
- O registro de auditoria deixa de guardar trechos do texto das análises da BlueJus IA, e os trechos antigos foram apagados.
- Passado o prazo de guarda, as conversas do Chat I.A também são apagadas, com os arquivos anexados nelas, como já acontecia com as análises.
- Os arquivos enviados ao sistema só são entregues a quem está logado no escritório certo, também por uma segunda barreira no servidor.
- O aviso de pagamento do banco Cora fica protegido contra excesso de chamadas, sem mudar nada para quem apoia pelo PIX.
- A ferramenta interna de consulta da equipe técnica ficou mais segura contra consultas que travariam o banco.
