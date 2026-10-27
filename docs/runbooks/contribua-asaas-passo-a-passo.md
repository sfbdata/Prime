# Asaas: o que o dono faz no painel (Contribua, C3a)

Para: o dono do BlueJus. São três tarefas no site do Asaas: a conta de testes (sandbox), a nota fiscal e o
aviso de pagamento (webhook). Nenhuma delas pede código. Quando terminar, mande para a sessão do Contribua
só o que está marcado com **➜ Enviar**, pelo canal seguro que vocês já usam para segredos (nunca no chat do
GitHub nem no WhatsApp).

> Os nomes dos menus vêm da documentação e da Central de Ajuda do Asaas (outubro de 2026). Se algum botão
> estiver com outro nome na sua tela, procure o mais parecido no mesmo menu e me avise para eu corrigir aqui.

## 1. Conta de testes (sandbox) e chave de API

1. Abra **https://sandbox.asaas.com** no computador (não no aplicativo do celular).
2. Clique em **Criar conta** e faça o cadastro normal. A conta de testes é separada da conta real:
   precisa criar mesmo que já tenha conta no Asaas.
3. Entre com o usuário **administrador** (só ele vê o menu de integrações).
4. Clique no **ícone do usuário**, no canto superior direito, e depois em **Integrações**.
5. Clique em **Chave de API** e depois em **Gerar nova chave de API**.
6. A chave aparece **uma vez só**. Copie na hora.
   **➜ Enviar:** a chave de API do sandbox.

## 2. Nota fiscal de serviço (NFS-e) automática

É o que permite a garantia "Nota fiscal enviada por e-mail todo mês" da tela. Faça primeiro no sandbox
(para testarmos) e, depois, repita na conta real.

1. No menu lateral, clique em **Notas Fiscais**.
2. Clique em **Configurações**.
3. Preencha as **informações fiscais** da empresa que emite a nota. Tenha em mãos, com o contador:
   - a **inscrição municipal** da BlueJus Gestão e Tecnologia LTDA (Brasília/DF);
   - o **regime tributário** (Simples Nacional ou outro);
   - o acesso à prefeitura que o Asaas pedir (usuário e senha do emissor do DF, ou o certificado
     digital), conforme o que a tela mostrar para Brasília.
4. Em **Notas Fiscais > Configurações > Serviços**, cadastre o serviço da assinatura:
   - **código do serviço municipal** e **descrição** (o contador indica o código da lista de serviços
     para "licenciamento de programa de computador / armazenamento de dados");
   - **alíquota de ISS** e as retenções (PIS, COFINS, CSLL, INSS, IR), também pelo contador;
   - marque este serviço como **padrão**.
5. **➜ Enviar:** o **código do serviço municipal** e a **descrição** exatamente como ficaram cadastrados,
   e as alíquotas. O sistema usa esses valores para ligar a nota automática em cada assinatura.

## 3. Aviso de pagamento (webhook)

É por aqui que o Asaas avisa o BlueJus que um escritório pagou, e o espaço é liberado na hora.

1. Clique no **ícone do usuário** > **Integrações** > **Webhooks**.
2. Clique em **Criar Webhook** e preencha:
   - **Nome:** `BlueJus Contribua`
   - **URL:** `https://bluejus.com.br/api/webhooks/asaas` (no sandbox, eu passo a URL de testes antes)
   - **E-mail:** o seu, para receber aviso se o envio falhar
   - **Versão da API:** a mais recente oferecida (v3)
   - **Token de autenticação:** deixe o Asaas gerar, ou cole um texto longo e aleatório (de 32 a 255
     caracteres, sem espaços)
   - **Fila de sincronização:** ativada · **Status:** ativado · **Tipo de envio:** sequencial
3. Na lista de **eventos**, marque só estes:
   - `CHECKOUT_PAID`, `CHECKOUT_CANCELED`, `CHECKOUT_EXPIRED`
   - `SUBSCRIPTION_CREATED`, `SUBSCRIPTION_UPDATED`, `SUBSCRIPTION_INACTIVATED`, `SUBSCRIPTION_DELETED`
   - `PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED`, `PAYMENT_OVERDUE`, `PAYMENT_REFUNDED`
   - `INVOICE_AUTHORIZED`, `INVOICE_ERROR`
4. Clique em **Salvar**. O token aparece **uma vez só**.
   **➜ Enviar:** o token de autenticação do webhook.

## 4. Depois do teste, a conta real

Repita os itens 1 a 3 em **https://www.asaas.com** (conta real), com a URL de produção do item 3. A
chave de API e o token da conta real são **segredos de produção**: vão direto para o servidor, pela
pessoa que faz o deploy, e nunca para o repositório.

## Fontes

- Chaves de API: https://docs.asaas.com/docs/chaves-de-api · Sandbox: https://docs.asaas.com/docs/sandbox
- Notas fiscais: https://central.ajuda.asaas.com/hc/pt-br/articles/32088045850779-Como-emitir-notas-fiscais ·
  https://docs.asaas.com/docs/faq-de-notas-fiscais
- Webhooks pelo painel: https://docs.asaas.com/docs/criar-novo-webhook-pela-aplicacao-web
