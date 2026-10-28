# Spec: tela "Meu Escritório" (desenho 13 VS 28.10.26)

Risco: ALTO (identidade do Tenant: nome, CNPJ e logo do escritório, e a porta da exclusão da conta). Pedido da
coordenação em 28/10. Desenho: `docs/design/redesign-28-10-26/13 - Meu Escritorio - VS 28.10.26.dc.html`, regras em
`bj-contas.js` (`atualizarEscritorio`, `codigoConta`). O desenho manda; omissão do desenho, vale a convenção do
sistema; desvio exige pergunta.

## Estado da frente

- Branch `meu-escritorio-28-10`. Migration reservada: `Version20261028150000` (uma só).
- Decisões do dono repassadas pela coordenação: D1 (só o primário e o Master editam, no servidor, tentativa auditada),
  D2 (exclusão segue o pedido vigente, 365 dias, confirmada pelo dono: o "30 dias" do desenho NÃO entra),
  D3 (Drive: ligar ao que existe), D4 (logo pela skill de arquivos), D5 (migration), D6 (sidebar/base em commit
  separado, avisando antes).

## O que já existe (reaproveitar)

- Tela atual: `TenantController::show` (`app_tenant_show`, GET `/tenant/{tenantId}`, `templates/tenant/show.html.twig`),
  com a porta de leitura "Master ou vínculo ativo com `admin.tenant.settings.manage`". O menu lateral "Meu Escritório"
  já aponta para ela. A edição legada (`app_tenant_edit`, abas de cargos, lotações e sedes) continua como está; não é
  desta frente.
- Entidade `Tenant` (`name`, `cnpj` com 14 posições, `cep`, `logradouro`, `numero`, `complemento`, `bairro`, `cidade`,
  `estado`, `isActive`, `createdAt`, `excluidoEm`), `Auditavel` (o `AuditLogSubscriber` já grava as mudanças).
- `RegraDosPrimarios::ehPrimario` e `podeGerirPrimarios` (primário = vínculo ativo com papel de sistema), `RegraDosMasters`.
- Validador `App\Validator\Cnpj` e `DigitoVerificador` (CPF e CNPJ com dígito).
- Drive: módulo `Sync` (`TenantDriveConexao`, `DriveConexaoController` com iniciar, callback e desconectar, portão
  `admin.tenant.settings.manage`), sem tarefa agendada e sem histórico.
- Exclusão: `ExclusaoDaContaController` (código por e-mail + palavra EXCLUIR, `PedidoDeExclusaoDeConta`, 365 dias de guarda
  contados da decisão do Master), hoje só no card do Meu Perfil.
- Perfis: `app_tenant_role_index`.

## Tela (de cima para baixo, só o que o desenho mostra)

Fora do desenho e sem lugar na tela: cabeçalho azul e abas do protótipo (a moldura do sistema já existe), os KPIs,
a equipe e o plano que o JS do desenho calcula mas a marcação não usa, e a exportação em JSON (idem). A linha "N
informação(ões) faltando" também está só no JS, não na marcação: não entra (tirar da lista da coordenação, ver
relatório).

1. **Informações do Escritório** (cartão com título azul `#0a6fa0`, ícone `bi-info-circle`).
   - Faixa da logo: quadro 64x64 tracejado, "Logo do escritório", "Usada no papel timbrado dos Modelos e nos documentos
     gerados.", botão "Enviar logo" ou "Trocar logo" e lixeira "Remover logo" (só quem edita; sem logo, ícone `bi-image`).
   - Linhas (zebra `#f2f2f2` / `#fff`): Código da conta (monoespaçado, copiar), Nome, CNPJ / CPF (copiar), Registro OAB
     (copiar), E-mail (copiar), Telefone (copiar), Endereço (`endereço · cidade · CEP n`), Criado em (`dd/mm/aaaa hh:mm:ss`)
     e Status (selo "Ativo" verde ou "Em quarentena" vermelho, dica com plano e espaço usado). Vazio: "Não informado".
   - Rodapé `#f7f9fb`: "Alterado por X em dd/mm/aaaa hh:mm" ou "Sem alterações registradas"; botões "Perfis" (para
     `app_tenant_role_index`) e "Editar" (só quem edita); em edição, "Cancelar" e "Salvar".
   - Modo edição (grade de campos): Nome do escritório, CNPJ ou CPF, Registro na OAB, E-mail, Telefone, CEP, Endereço,
     Cidade / UF, com os placeholders do desenho. Preencher o CEP (8 dígitos) busca o endereço e CNPJ completo e válido busca
     a Receita, como o desenho, reaproveitando os mesmos scripts que o cadastro de cliente e o de sedes já usam (viacep e
     brasilapi no navegador): nenhuma chamada externa nova no servidor.
2. **Backup de arquivos** (título azul, `bi-cloud-arrow-up`, selo "Conectado" / "Não conectado" à direita).
   - Texto do desenho e o botão do Google ("Conectar meu Drive" ou "Drive conectado"), ligados à conexão que já existe
     (`TenantDriveConexao.ativo`): desconectado, o botão inicia a conexão existente; conectado, o botão leva à página
     de conexão, onde já se desconecta (com confirmação).
   - Linha de situação: desconectado, "Backup desativado: conecte o Drive para começar".
   - **Fora, por não existir no sistema (D3), vira desvio no relatório:** "Fazer backup agora", "Backup automático semanal
     (segunda, 2h)", "Ver histórico" e a linha "Último backup". Sem tarefa agendada nova.
3. **Excluir conta** (cartão de borda `#e6b9be`, título vermelho `#b02a37`; só quem pode excluir). Recolhido: "Opções
   para excluir o escritório. Use só se tiver certeza." e "Mostrar opções de exclusão". Aberto: texto da exclusão com a
   regra vigente (a conta é suspensa a pedido e o dono da plataforma decide; os dados ficam guardados por 365 dias e o
   suporte ou o Master pode recuperar; nada de "irreversível"), campo "Senha do usuário primário:", "Digite {nome} para
   confirmar:" e o botão "Excluir escritório" (desabilitado até a senha e o nome conferirem), "Ocultar".
   - A ação segue o pedido vigente: a senha e o nome são conferidos NO SERVIDOR (rota nova, limite de tentativas) e
     só então segue o fluxo existente (motivo, código por e-mail, palavra EXCLUIR; `ExclusaoDaContaController`). Nenhum
     UseCase de exclusão existente muda de regra.

## Dados novos (migration `Version20261028150000`)

Colunas novas em `tenant`, todas nulas, sem DROP: `oab` VARCHAR(40), `email_contato` VARCHAR(180), `telefone`
VARCHAR(20), `logo_chave` VARCHAR(255), `alterado_em` TIMESTAMP, `alterado_por_id` INT (FK `user`, ON DELETE SET NULL),
`codigo_conta` VARCHAR(16) com índice único parcial (`WHERE codigo_conta IS NOT NULL`). A migration só tem ADD COLUMN
nulo e o índice: sem UPDATE e sem DROP no `up()` (a guarda do deploy para a publicação se houver). O `codigo_conta` é
gerado sob demanda: no primeiro acesso à tela, se estiver nulo, o UseCase de consulta gera (`BJ-XXXX-XXXX-XXXX`,
alfabeto do desenho sem letras ambíguas), grava, tenta de novo se bater no índice, e a trilha registra. Escritório novo
já nasce com o código. Reversível (`down` remove).

Mapeamentos:
- CNPJ ou CPF: a coluna `cnpj` guarda 11 ou 14 dígitos. Validação como o desenho (14: dígito do CNPJ; 11: CPF; outro
  tamanho recusa com a frase do desenho) mais o dígito do CPF, que é convenção do sistema.
- Endereço: um campo único no desenho. Gravado em `logradouro`; `numero`, `complemento` e `bairro` só são zerados quando
  o texto do endereço mudou (quem não mexe no endereço não perde nada). Exibido como `logradouro, numero, complemento,
  bairro`. Cidade / UF: "Cidade/UF" vira `cidade` e `estado` (duas letras).
- "Alterado por X em": colunas `alterado_por_id` e `alterado_em`, gravadas em toda alteração de dados ou de logo.
- Logo: arquivo pela skill `armazenamento-de-arquivos`, categoria nova `TENANT_LOGO` (imagem, limite de tamanho e tipo
  conferidos no servidor, redimensionada), guardada por chave em `logo_chave`; remoção do arquivo só depois do COMMIT.
  Servida por rota autenticada do escritório (nunca caminho público). Se a exportação de Modelos não tiver ponto de
  cabeçalho simples, a logo fica guardada e exibida e o papel timbrado não é alterado (desvio, relatório); o texto do
  desenho sobre o papel timbrado fica como está e o dono confirma.

## Regras (D1) e segurança

- Ler a tela: a mesma porta de hoje (Master ou vínculo ativo com `admin.tenant.settings.manage`). Não se amplia.
- Salvar dados, enviar e remover a logo, conferir a exclusão: só o primário do escritório da sessão
  (`RegraDosPrimarios::ehPrimario`) ou o Master. Secundário recebe 403, mesmo que mande o POST à mão, e a tentativa
  fica na trilha (`audit_log`, ação própria de acesso negado, com usuário, escritório e rota, sem o conteúdo enviado).
  O botão "Editar", a lixeira, o envio de logo e o cartão de exclusão nem aparecem para o secundário.
- O escritório vem sempre da sessão/rota validada, nunca de campo do formulário. Escritório A não lê nem grava o B
  (teste de isolamento obrigatório). CSRF em todos os POST. Conta criada por Master não mostra o cartão de exclusão
  (regra E2 do pedido vigente).
- Campos de texto limpos (aparar, tamanho máximo), e-mail e telefone validados, nenhum HTML aceito. CPF e CNPJ são
  dado pessoal: ficam fora dos logs de erro (a trilha de auditoria já os trata como os demais campos do Tenant).

## Testes (antes do código)

- Unit: UseCase de salvar (primário grava e carimba quem alterou e quando; secundário recusado e auditado; CNPJ
  inválido, CPF inválido, tamanho errado, e-mail inválido e nome vazio recusados; endereço só zera número e
  complemento quando mudou), UseCase da logo (primário envia e remove; tipo e tamanho inválidos recusados; arquivo
  antigo só sai depois do COMMIT), conferência da exclusão (senha errada, nome diferente, limite de tentativas).
- Functional: primário edita e vê "Alterado por"; secundário recebe 403 no POST e não vê os botões; Master edita;
  tenant A não altera B; CSRF; CNPJ inválido volta com a frase do desenho; arranjo por filho direto (`.me-pagina >
  .me-cartao`, grade de linhas e faixa da logo dentro do primeiro cartão); o que o desenho não tem não aparece (sem
  abas, sem KPI, sem "Fazer backup agora").
- Texto: nenhum travessão nos textos novos; chaves de tradução no catálogo do Tenant.

## Fatias

| Fatia | Conteúdo | Estado |
|---|---|---|
| M1 | Migration, entidade, UseCases (salvar, logo, conferir exclusão), testes | a fazer |
| M2 | Template e CSS do desenho (informações, logo, backup, exclusão) | a fazer |
| M3 | Auditoria da tentativa negada, conferência ✓/✗, revisão independente | a fazer |
| M4 | Item de menu (só se tocar `_sidebar` ou `base`; commit separado) | provavelmente nenhum |
