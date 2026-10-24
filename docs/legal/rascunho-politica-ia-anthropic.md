# RASCUNHO para o jurídico: BlueJus IA (Anthropic) na Política de Privacidade

**Situação:** rascunho de 24/10/2026, **não publicado**. O texto vigente continua sendo o de
`app/templates/legal/_politica_privacidade_texto.html.twig` (versão `1.0.2026`). Nada aqui vale para o
usuário até o jurídico aprovar e o texto entrar no template.

Origem: decisões do dono de 24/10/2026 em `docs/specs/ia-anthropic-ligar.md` §10 (retenção zero pedida à
Anthropic, processamento nos EUA, leitura de documentos liberada, respostas guardadas por 12 meses).

## 1. Fatos que o texto precisa refletir

| Fato | Fonte |
|---|---|
| Fornecedor do modelo: Anthropic PBC (EUA), API Claude. | decisão D1 do dono, 14/10/2026 |
| Processamento nos EUA (`inference_geo: "us"`); não há região Brasil. | docs da Anthropic, data residency, lida em 24/10/2026 |
| Com a retenção zero (ZDR), a Anthropic não guarda prompts nem respostas depois de devolver a resposta. Exceções: conteúdo sinalizado pelos sistemas de segurança e obrigação legal. **Depende do pedido do dono ser aceito** (§4). | docs da Anthropic, API and data retention, lida em 24/10/2026 |
| A Anthropic não treina modelos com dados de clientes da API. | Commercial Terms da Anthropic |
| O que vai ao modelo: dados da pasta (número, situação, ação, responsável), processo vinculado, movimentações, metas, anotações, checklist, **nome** do cliente, e, a partir da fase de documentos, **o conteúdo dos documentos** que o usuário mandar ler. Financeiro só se quem pede pode vê-lo. | `app/src/Inteligencia/CLAUDE.md`, regras 4 a 7 |
| Nunca vai: processo em segredo de justiça, CPF/CNPJ, e-mail, telefone e endereço do cliente (mascarados). | mesmas regras |
| O BlueJus guarda a **resposta** da IA e a **pergunta** feita, não o que foi enviado. Prazo proposto: 12 meses. | §10 da spec (Q5) |
| Quem liga: o Master libera o escritório, e o administrador do escritório liga com aceite registrado (data e autor). | §10.1 da spec |

## 2. Alterações propostas no texto

### Capítulo 11 (Inteligência artificial): acrescentar ao fim

> As funcionalidades de inteligência artificial da plataforma (BlueJus IA) utilizam modelos da Anthropic PBC,
> contratada como suboperadora nos termos do Anexo I, em regime de retenção zero de dados: o conteúdo submetido
> ao modelo não é armazenado pela fornecedora após a devolução da resposta, ressalvadas as hipóteses de
> obrigação legal e de conteúdo sinalizado pelos mecanismos de segurança da própria fornecedora. A fornecedora
> não utiliza esse conteúdo para treinamento de modelos.
>
> A BlueJus IA somente é ativada para um escritório mediante aceite expresso do seu administrador, registrado
> com data e autoria. Não são submetidos ao modelo os processos sob segredo de justiça nem o número de
> inscrição no CPF ou CNPJ, o endereço eletrônico, o telefone e o endereço dos clientes do escritório. Quando o
> usuário solicita a leitura de um documento, o conteúdo desse documento é submetido ao modelo para essa
> finalidade.
>
> A plataforma conserva a resposta produzida pelo modelo e a pergunta formulada pelo usuário pelo prazo
> indicado no Anexo II, sem conservar a íntegra do conteúdo submetido.

### Capítulo 14 (Transferência internacional): acrescentar

> A funcionalidade BlueJus IA envolve transferência internacional de dados aos Estados Unidos, onde é realizado
> o processamento pela suboperadora indicada no Anexo I, com fundamento em [MECANISMO A DEFINIR PELO JURÍDICO,
> ver §3].

### Anexo I: nova linha

| Suboperador | Finalidade | Categoria de dados | Localização |
|---|---|---|---|
| Anthropic PBC (API Claude) | Processamento das funcionalidades de inteligência artificial (BlueJus IA), quando ativadas pelo contratante | Conteúdo do Usuário submetido à funcionalidade (dados de pastas, processos, movimentações, metas, anotações, nome de clientes e documentos selecionados), sem retenção pela fornecedora | Estados Unidos |

### Anexo II: nova linha

| Categoria | Prazo | Fundamento |
|---|---|---|
| Respostas da BlueJus IA e perguntas formuladas | 12 meses a partir da geração | Necessidade (art. 6º, III, da LGPD) e Termos e Condições Gerais de Uso |

### Anexo III: nova versão

Subir para `1.1.2026` (ou o número que o jurídico definir) com a linha "Inclusão da Anthropic PBC como
suboperadora da BlueJus IA".

## 3. Para o jurídico decidir

1. **Base da transferência internacional (art. 33 da LGPD).** O DPA da Anthropic traz as cláusulas-padrão da
   UE, do Reino Unido e da Suíça, mas **não** as cláusulas-padrão da ANPD (Resolução CD/ANPD nº 19/2024).
   Opções: pedir à Anthropic a adesão às cláusulas da ANPD; outro mecanismo do art. 33; ou o consentimento
   específico do art. 33, VIII, colhido no aceite do escritório. É a decisão que trava a publicação.
2. **Papel de cada parte.** Escritório = controlador; BlueJus = operador; Anthropic = suboperadora. Confirmar
   e conferir se o contrato com os escritórios já autoriza suboperador novo com comunicação prévia (cap. 13).
3. **Comunicação prévia aos contratantes** antes de a Anthropic receber Conteúdo do Usuário (o Anexo I promete
   isso). Hoje só o escritório do dono será ligado no começo.
4. **Texto do aceite** na tela do administrador do escritório (`/admin/inteligencia`). Proposta para trocar
   o texto atual:

   > Ao ligar a BlueJus IA, o escritório autoriza o envio dos dados listados acima à Anthropic PBC, nos Estados
   > Unidos, para gerar as análises, os resumos e as respostas pedidos pela equipe. A Anthropic não guarda esses
   > dados depois da resposta (retenção zero) e não os usa para treinar modelos. Processos em segredo de justiça
   > e CPF, CNPJ, e-mail, telefone e endereço dos clientes nunca são enviados. As respostas ficam guardadas no
   > BlueJus por 12 meses. O aceite fica registrado com a data e o seu nome.

## 4. O que o dono precisa ter em mãos antes de publicar

1. Retenção zero (ZDR) aceita pela Anthropic, por escrito (pedido em `https://claude.com/contact-sales`, para a
   organização da Console onde está a chave do BlueJus).
2. O DPA da Anthropic assinado ou aceito, arquivado.
3. Workspace de produção na Console com `allowed_inference_geos: ["us"]`.

Sem o item 1 aceito, os trechos sobre "retenção zero" **não podem** ir ao texto publicado: viraria promessa que
o sistema não cumpre (precedente: as duas pendências do topo de `docs/specs/PROGRESSO-PENDENCIAS.md`).

## 5. Ao publicar (tarefa de desenvolvimento)

- Levar o texto aprovado a `app/templates/legal/_politica_privacidade_texto.html.twig` e o aceite a
  `app/templates/inteligencia/admin/configuracao.html.twig` (com o `AvisoDeEnvioCobreOContextoTest` verde).
- Subir `App\Legal\PoliticaPrivacidadeVigente::VERSAO`.
- Sem travessão em nenhum trecho publicado.
