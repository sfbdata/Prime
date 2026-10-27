# Spec: "Salvar edição" e "Novo escritório" na Nova solicitação (Trabalho Externo)

Desenho: `docs/design/redesign-25-10-26/06 - Trabalho Externo -25.10.26.dc.html`, seção SOLICITANTE do modal "Nova solicitação" (l.685 a 696; JS l.1435 a 1439). Risco: **MÉDIO** (cadastro por usuário, dado de contato). Migration reservada: `Version20261027153100`.

## Decisão
O select "Escritórios da sua conta" hoje lista os tenants a que o usuário pertence. O desenho deixa o usuário gravar e editar dados de escritório ali. Editar ou criar **Tenant** a partir do formulário de uma solicitação seria brecha de autorização, então os escritórios salvos ficam em **tabela própria do usuário** e aparecem no mesmo select, depois dos tenants. O Tenant nunca é alterado.

## Comportamento (do desenho)
- **Novo escritório** (botão com ícone +, title "Guardar os dados digitados como um novo escritório"), sempre visível. Sem nome digitado: erro "Informe o nome do escritório para salvar." Com nome: grava o escritório com nome, e-mail, telefone, UF e cidade digitados, seleciona-o no select e mostra o toast "Escritório salvo" / "{nome} fica disponível nas próximas solicitações."
- **Salvar edição** (ícone lápis, title "Gravar as alterações neste escritório"): só aparece quando há um escritório **salvo pelo usuário** selecionado e nome, e-mail, telefone, UF ou cidade digitados divergem do cadastro. Grava as alterações; toast "Escritório atualizado" com o nome; o botão some. Escritório que é tenant não é editável por aqui: o botão não aparece.
- Nome vazio no "Salvar edição": 422 com a mesma mensagem (o desenho não valida; o servidor sim).
- Sem exclusão (o desenho não tem).

## Dado
Tabela `trabalho_externo_escritorio_solicitante`: id, usuario_id (FK User, ON DELETE CASCADE), nome (120), email (180, nulo), telefone (30, nulo), uf (2, nulo), cidade (80, nulo), criado_em, atualizado_em. Índice por usuario_id. Não é tenant-aware (pertence ao usuário, como `PreferenciaDoUsuario`).

## Segurança
- Só o dono (usuário logado) lista, cria e edita os seus; id de outro usuário: 404.
- Valores só do servidor (trim, tamanhos máximos, UF entre as válidas, e-mail em formato válido se informado). CSRF nas escritas. Teto de 20 escritórios salvos por usuário (409 acima).
- O escritório salvo só preenche os campos de texto da solicitação; nunca vira `escritorioId` (tenant).

## Tela
Botões no padrão do desenho (altura, cor, ícone), na linha do select, componentes do desenho. Lista salva vem em `escritorios` do painel, com `salvo: true`.

## Testes
Unit dos UseCases (criar, atualizar, validações, dono, teto) e Functional (rotas, CSRF, outro usuário 404, aparece no painel, tenant intocado).
