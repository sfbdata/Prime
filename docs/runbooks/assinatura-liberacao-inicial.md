# Liberar a Assinatura sem contratação nova

Responsável pela configuração de produção e pela publicação: Samuel. Este roteiro complementa os runbooks
da Assinatura e não muda o caminho obrigatório de branch, PR, checks, merge e release.

## 1. Preparar antes da publicação

Criar e guardar no cofre os valores próprios da Assinatura, sem substituir valores existentes:

| Configuração | Necessidade | Instrução |
|---|---|---|
| `ASSINATURA_SEGREDO` | Link e código por e-mail, inclusive Simples | [Criar o segredo](assinatura-segredo-em-producao.md) |
| `ASSINATURA_SELO_SEGREDO` | Código BJA para consultar o documento | [Criar o segredo do código](assinatura-selo-segredo-em-producao.md) |
| `ASSINATURA_SELFIE_CHAVE` | Usada pela Reforçada; exigida pelo conferente geral da publicação | [Preparar a chave da selfie](assinatura-selfie-chave-em-producao.md) |

Os runbooks individuais incluem um redeploy. Se os valores forem preparados antes da nova versão, o deploy normal
da release os carrega: não repetir o deploy de cada runbook. Nunca imprimir valores nem usar
`debug:container --env-vars`. Não trocar a chave dos links com documentos em andamento.

Para começar pela Simples, não é necessário ligar o profile `assinatura`, instalar o validador de certificados,
comprar um e-CNPJ ou contratar biometria. Manter o SMTP já usado pelo sistema. Não alterar os outros profiles do compose.
Os nomes `ASSINATURA_VALIDADOR_URL` e `ASSINATURA_VALIDADOR_TOKEN` podem continuar vazios neste primeiro uso.

## 2. Publicar pelo fluxo normal

Com os checks do PR verdes, o Samuel faz o merge e publica a release pelo processo do projeto.
A chave geral `assinatura` deve permanecer desligada para os escritórios durante a primeira conferência do Master.
O Master consegue conferir a tela com essa chave desligada; isso não cria uma segunda chave exclusiva do Master.
As páginas públicas também são bloqueadas enquanto a chave estiver desligada. Nesse primeiro teste, abrir o convite
e Validar no mesmo navegador com a sessão Master autenticada. Outro navegador ou uma janela anônima recebe 503.

Esta entrega acrescenta quatro migrations, nesta ordem numérica:

1. `Version20261109260000`: modelos do escritório.
2. `Version20261109270000`: dados da selfie.
3. `Version20261109290000`: código, PDF final e vínculo à pasta.
4. `Version20261109300000`: versões do PDF assinado.

As versões 260000 e 270000 podem estar pendentes mesmo com a 280000 do Ponto já executada.
Conferir que as quatro foram executadas. As quatro pertencem à Assinatura; não renumerar migrations já entregues.

## 3. Conferir a configuração sem revelar valores

Executar manualmente no terminal externo da VPS, após o deploy:

```bash
cd /opt/jusprime
docker exec -w /var/www/app jusprime_php_prod php bin/console doctrine:migrations:status
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T php php /var/www/app/bin/console app:conferir-segredos --modelo=- --no-interaction < .env.prod.example
```

Preparar os três segredos da tabela: embora a Simples use apenas os dois primeiros, o conferente geral e o
`go-live-check.sh` reprovam quando falta a chave de selfie. Ausência do validador mantém gov.br e ICP-Brasil desligados
e é tratada como aviso. Conferir os hosts permitidos conforme o runbook do link e manter o worker saudável para o PDF final.

## 4. Testar no domínio real, com a conta Master

1. Criar um documento fictício, escolher Simples e enviar somente ao próprio e-mail. Manter a sessão Master no navegador.
2. Abrir o convite no Gmail, pedir o código, concluir a assinatura e baixar o PDF.
3. Consultar o código BJA em `/assinar/validar` e conferir documento concluído e signatário.
4. Criar outro documento fictício, deixar pendente e usar Enviar lembrete na lista. Conferir a nova mensagem.
5. Com esse teste aprovado, ligar a chave Assinatura no Master e repetir o fluxo em janela anônima, incluindo convite,
   código e consulta. A chave é global: ao ligar, os escritórios autorizados pelo módulo também ganham acesso.

Se a conferência anônima falhar, desligar a chave novamente no Master e investigar antes de anunciar a disponibilidade.
Com a chave desligada, os links dos signatários também ficam indisponíveis.

O botão E-mail do detalhe abre o aplicativo de e-mail para compartilhamento manual. O reenvio feito pelo sistema
é Enviar lembrete, na lista, para o primeiro signatário pendente, limitado a um por hora.
Aceitação pelo SMTP não confirma chegada à caixa de entrada. Conferir a mensagem no destinatário e nas abas do Gmail.

## 5. Recursos adicionais e limites

- O QR e o código BJA consultam o registro. O PDF pode ser gerado sem assinatura digital e-CNPJ do BlueJus.
  A moldura e o código visual não são um certificado digital.
- A Reforçada coleta selfie e localização, sem comparação facial. Há pendências de prazo de guarda, aviso de
  privacidade e acesso interno à selfie em [etapa 7, lacunas](../specs/assinatura-documentos-etapa-7.md#12-lacunas).
  Não considerar esses pontos encerrados pelo teste da Simples.
- gov.br e ICP-Brasil exigem o [validador próprio](assinatura-validador-em-producao.md) e aceite com PDFs reais.
  Sem ele, o sistema recusa esses métodos e orienta usar Simples. O signatário ICP precisa do próprio certificado.
- A câmera ao vivo para QR continua bloqueada pelo nginx atual. Usar código digitado ou foto do QR. A alteração
  do nginx não faz parte desta entrega. O DSS/B-LT também fica para outra frente.
- Para o selo digital opcional, seguir o [runbook do e-CNPJ](assinatura-selo-ecnpj-em-producao.md) em outra etapa.
  Não ligar o profile só para usar Simples: ele acrescenta requisitos próprios ao deploy.
