# Central de Comunicação: avisos do sino na Caixa de entrada (F1)

Pedido do dono em 16/10/2026: levar ao Chat I.A tudo o que funciona com dados do próprio sistema, sem IA externa.
Esta é a entrega 1 do levantamento (o sino dentro da Caixa). Merge no `master` só depois da aprovação dele na demo.

Risco: MÉDIO (avisos com nome de meta, pasta e NUP; acesso a pasta pode ter sido revogado depois do aviso).
Sem migration.

## 1. Fonte no desenho (`bluejus-central.js`, pacote de 13/10)

- Tipos e grupos (L61-87): cada aviso tem grupo `urgente | atencao | mencoes | mensagens | metas`, nível, ícone, cor e
  rótulo. Caixa "Tudo" = conversas + avisos; filtros de uma bolinha (L5776): Tudo, Conversas, Urgente, Atenção,
  Menções, Metas, Para revisar.
- Cartão do aviso (L5603-5630): faixa lateral de 3 px na cor do tipo, ícone 30×30, rótulo do tipo em caixa alta,
  hora, título (700 não lido / 500 lido), texto em até 2 linhas, fundo com a cor do tipo bem clara. Clique abre.
- Painel (L5632): 5 cartões com contagem (Urgente, Atenção, Menções, Mensagens, Metas).
- Lançador (L6399-6409): contador = não lidas (avisos + mensagens); VERMELHO `#c0392f` + `bjcUrg` 1,6 s quando há aviso
  não lido de nível 3 e os alertas visuais estão ligados.

## 2. Regras

1. **Fonte:** a tabela `notificacao` do sino, só as DESTE usuário NESTE escritório (tenant explícito, além do filtro).
   Nada novo é gravado: a Central lê e marca como lida/não lida pelo mesmo serviço do sino.
2. **Fora da Caixa:**
   - avisos de gestão (`Notificacao::TIPOS_GESTAO`: justificativa de ponto enviada, chamado novo e atribuído): seguem
     só no sino, na aba Gestão (hoje são centenas não lidas e pintariam o contador de vermelho para todos);
   - avisos da própria Central (`central_mencao`, `central_grupo_adicionado`): a conversa já mostra a menção e o grupo.
3. **Mapa tipo real → desenho** (`TiposDeAvisoDaCentral`, único lugar):

   | Tipo real | Desenho | Grupo | Nível |
   |---|---|---|---|
   | `tarefa_alerta_verificar` | Alerta | urgente | 3 |
   | `tarefa_criada` | Nova meta | metas | 2 |
   | `tarefa_em_revisao`, `tarefa_pendente` | Meta atualizada | metas | 1 |
   | `tarefa_concluida` | Meta concluída | metas | 0 |
   | `pasta_mencao_registro`, `nota_tecnica_mencao` | Menção | mencoes | 2 |
   | `pasta_resposta_registro` | Para você | atencao | 2 |
   | `djen_publicacao` | Publicação (sem par no desenho) | atencao | 2 |
   | `evento_criado`, `evento_cancelado` | Agenda (sem par) | atencao | 1 |
   | `ia_analise_concluida` | Documento analisado | mensagens | 1 |
   | demais (ex.: resultado de justificativa de ponto, chamado) | Aviso (sem par) | só em "Tudo" | 1 |

4. **Acesso rechecado na leitura:** aviso ligado a uma pasta (pela meta ou por URL `/pasta/{id}`) só aparece se a pessoa
   ainda pode ver a pasta (`PermissionChecker::canAccessResource(pasta, view)`) ou, se for de meta, é responsável por
   ela. Sem acesso, o aviso não sai (nem título, nem texto) e não conta.
5. **Ler:** abrir o aviso marca como lido e vai ao link (o mesmo do sino: URL gravada ou a meta). Menu de botão direito:
   Abrir, Marcar como lido / não lido. Rotas da Central com CSRF e posse + tenant.
6. **Contadores:** `/central/novidades` passa a levar `avisosNaoLidos`, `avisosUrgentes` e `ultimoAviso` (contagens com
   tenant explícito e as mesmas exclusões), para o lançador sem carregar a lista. A lista (`GET /central/avisos`, até
   100, mais recentes primeiro) carrega ao abrir a janela e quando `ultimoAviso` ou as contagens mudam.

**Fora (sem regra ou sem dado):** "dar baixa / resolvida" e "resolvidas antigas" (o sino só tem lida; exigiria
migration e regra), "Para revisar", "Encaminhar" aviso, selo URGENTE manual, "Excluir" pela Central.

## 3. Testes

PHPUnit: lista só do usuário e do escritório; exclusões de gestão e da Central; mapa de grupos; aviso de pasta sem
acesso some; marcar lido/não lido com posse, tenant e CSRF; contagens nas novidades; isolamento com o filtro desligado.
Playwright comparativo: cartão, filtros e cartões do Painel iguais ao desenho; contador vermelho com `bjcUrg`.
