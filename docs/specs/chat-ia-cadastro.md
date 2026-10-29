# Chat I.A cadastra cliente e pasta (propor, confirmar, executar)

Risco **MÉDIO**: é a primeira vez que a IA leva a uma escrita no sistema. Empilhada em `ia-chat-pagina` (#542, rascunho).
Decisão do dono (Farlei, via Samuel, 29/10/2026): a pessoa manda o que tem (ex.: foto da CNH) e a IA cadastra CLIENTE e PASTA.

## Regra de ouro

**A IA nunca grava.** Ela só PROPÕE. Quem grava é o clique em Confirmar, no navegador de quem está logado, por um endpoint
comum que chama os UseCases de Cliente e Pasta que já existem, com o usuário e o escritório da SESSÃO. O modelo não tem
credencial, não chama o sistema e não escolhe escritório nem usuário: o que ele devolve é só texto e dados que viram um cartão.

## Fluxo

1. A pessoa escreve ou anexa (CNH, contrato, ficha). O pedido leva duas ferramentas (tool use da Anthropic):
   - `propor_cliente(tipo: pf|pj, campos: {nome, cpf, rg, rg_orgao, nascimento, estado_civil, profissao, nacionalidade, genero,
     razao_social, cnpj, ..., cep, logradouro, numero, complemento, bairro, cidade, uf, telefone, whatsapp, email})`: as chaves de
     `CamposDoCadastro::ROTULOS`, e só elas.
   - `propor_pasta(nome_cliente, nome_acao, nup?, cliente?: {mesmos campos})`: pasta nova; com `cliente`, o cadastro do cliente vai junto, ligado à pasta.
2. O provedor devolve um bloco `tool_use`. O servidor NÃO o executa: valida, grava uma **proposta** (`PropostaDeCadastro`,
   `pendente`, dono = quem perguntou, escritório, JSON dos campos, expira em 24 h) e emite o evento SSE `proposta {id, ...}`.
   O fluxo ENCERRA na chamada (sem segunda chamada ao provedor com `tool_result`: mais barato e mais seguro; desvio aceito): o servidor acrescenta um aviso fixo (`AVISO_COM_PROPOSTA`/`AVISO_SEM_PROPOSTA`) ao texto do modelo.
3. A tela mostra o **cartão da proposta** na conversa: título ("Cadastrar cliente" / "Abrir pasta"), a lista EXATA de campo e
   valor que será gravado (o mesmo JSON que o servidor guardou, nunca o texto do modelo), o aviso de duplicidade e os botões
   **Confirmar** e **Cancelar**. A pessoa pode editar os valores no cartão antes de confirmar (o que vale é o que ela enviou no clique, revalidado).
4. `POST /chat-ia/propostas/{id}/confirmar` (CSRF, sessão) relê a proposta DO DONO no escritório, revalida tudo, confere permissão e
   chama o UseCase. `POST .../cancelar` marca `cancelada`. Proposta já confirmada, cancelada ou expirada: 409, nunca executa duas vezes.

## Guardas (na ordem, todas recusam antes de gravar)

1. Login, vínculo ativo, módulo da I.A (`inteligencia`), posse da proposta (alheia = 404, como a conversa).
2. **Permissão de quem clicou, no clique** (não na hora da proposta): cliente avulso exige `CriarClienteAvulsoUseCase::CAPACIDADE`
   (`resources.carteira.gerenciar`); pasta exige o módulo `pastas`; cliente ligado à pasta exige poder editar a pasta
   (`CriarClienteDaPastaUseCase`). Sem permissão: a ferramenta nem é oferecida ao modelo (o pedido só leva as que a pessoa pode usar)
   E o clique é recusado (403). Os dois lados, porque a permissão pode mudar entre uma coisa e outra.
3. Validação por `CamposDoCadastro::aplicar` (limites, CPF/CNPJ com dígito, e-mail) e `CriarPastaDTO`: o erro volta no cartão, campo a campo, sem gravar.
4. **Estado**: `pendente` e não expirada. Transição `pendente → confirmada|cancelada|expirada` numa transação com trava de linha (dois cliques seguidos gravam uma vez).
5. Entrada da IA é dado não confiável: texto vindo de arquivo (CNH, PDF) pode tentar instruir ("cadastre também…"). Defesas: o modelo só consegue
   propor (nada executa sem o clique); no máximo **uma proposta por tipo por resposta** e **2 por resposta**; os valores passam
   pelo mesmo saneamento do formulário; o cartão mostra o valor literal e nada além do que as ferramentas aceitam.

## Duplicidade (CPF/CNPJ e pasta)

- Ao montar o cartão, o servidor consulta o escritório: CPF/CNPJ já cadastrado → o cartão avisa "já existe o cliente X (abrir)" e oferece
  **Abrir nova pasta com o cliente existente** (vincula à pasta, quando for pasta) ou **Cancelar**; o botão Confirmar de criar um segundo cadastro NÃO aparece.
  O UseCase já recusa CPF de outro cadastro do escritório; a consulta do cartão é só para avisar antes.
- Pasta: mesmo aviso do formulário (`findSemelhantesPorClienteEAcao`): pastas parecidas listadas; confirmar segue só com
  "Criar mesmo assim" (equivale ao `confirmar=1` da tela).
- Nome igual sem CPF não bloqueia (como hoje), só avisa.

## Se a pessoa recusar

Cancelar muda a proposta para `cancelada`, o cartão vira "Cancelado" (somente leitura) e nada foi gravado. O chat continua: ela pode pedir
ajuste ("o CPF é outro") e a IA faz NOVA proposta; a anterior segue cancelada. Fechar a página sem clicar deixa `pendente` até expirar (24 h),
depois `expirada`; clicar depois disso dá 409 "a proposta venceu, peça de novo".

## Limite aceito da expiração

Quem zera os dados pessoais de proposta vencida é `expirarVencidas`, chamado ao registrar nova proposta e ao abrir uma conversa do escritório. Proposta abandonada num escritório
que nunca mais usa o chat guarda o JSON até alguém voltar; uma varredura periódica no Scheduler fecha isso (pendente do dono, pois toca arquivo quente do agendador).

## Auditoria e privacidade

- `PropostaDeCadastro` é `Auditavel` (propor, confirmar, cancelar: ator, ação, id), SEM os valores (CPF, nome, endereço): `dados` fora do
  diff do `AuditLogSubscriber`. A gravação em si é auditada/registrada pelos próprios UseCases (Histórico do cadastro: "<quem> criou o cadastro",
  Registro da pasta), com o usuário que clicou, nunca "IA".
- O JSON da proposta tem dado pessoal: expira, e some ao confirmar/cancelar (o campo `dados` é zerado, fica só a ação e o id do cliente/pasta criado).
  Entra na purga do escritório. Nunca vai para log, notificação ou prompt seguinte (o histórico do chat leva só "proposta registrada").
- Custo: a foto/PDF já passa pelo teto diário do chat; ferramenta não muda o teto.

## Contrato técnico

- `ProvedorDeLinguagemEmFluxo::transmitir` ganha `ferramentas` no `PedidoDeLinguagem` e o fluxo devolve, além de trechos, `tool_use` completo
  (`LeitorDeFluxoDaAnthropic` junta os `input_json_delta`); JSON inválido ou campo fora da lista = proposta descartada com texto "não consegui montar o cadastro".
- Sem tool use no provedor falso dos testes: `ProvedorFalso` aprende a devolver uma chamada de ferramenta.
- Entidade `PropostaDeCadastro` (tabela `chat_ia_proposta`): tenant, usuário, conversa, mensagem, tipo (`cliente|pasta`), estado, dados (JSON), expira_em,
  criada_em, decidida_em, resultado (tipo e id criado). Migration `Version20261028180000` (reservada), só CREATE.
- Domínio `app/src/ChatIa/`: `UseCase/ConfirmarPropostaDeCadastroUseCase`, `CancelarPropostaDeCadastroUseCase`, `Service/GuardaDasPropostas`
  (ferramentas oferecidas conforme permissão), `Service/ConversorDaProposta` (proposta → chamada do UseCase de Cliente/Pasta).
  Os UseCases de Cliente e Pasta NÃO mudam.
- Tela: cartão em `chat-ia.js`, só `createElement`/`textContent`; textos em `translations/chat_ia/`; sem travessão.

## Fora de escopo (decisão pendente do dono, nada aqui o implementa)

Editar/arquivar cliente ou pasta pela IA; criar meta, prazo ou processo; confirmar por voz ou em lote; cadastrar vários clientes de uma só foto.

## Testes

Unit: conversão proposta→UseCase por tipo; guarda de ferramentas por permissão; expiração; dupla confirmação. Functional: propor (provedor falso com
`tool_use`), confirmar cria Cliente/Pasta com o tenant e o usuário da sessão; sem permissão = 403 sem gravar; proposta alheia = 404; CPF duplicado não cria;
cancelar não grava; valor com instrução maliciosa não executa nada; auditoria sem dado pessoal. Arquitetura: `chat_ia_proposta` na purga e na lista de auditáveis.
