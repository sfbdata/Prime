# Runbook: criar o segredo da Assinatura de documentos em produção

A Assinatura de documentos gera o link de cada signatário e confere o código de 6 dígitos com um segredo próprio,
`ASSINATURA_SEGREDO` (contrato `docs/specs/assinatura-documentos-etapa-2.md`, seção 3). Sem ele, a tela não envia
documento nem mostra "Copiar link": diz "Assinatura eletrônica sem chave configurada. Fale com o suporte.".

**Quando**: antes de ligar a chave `assinatura` no Master (fim da etapa 3). Quem faz: o Samuel, na VPS.

Este arquivo **não contém segredo nenhum**. O valor só existe no `.env.prod` da VPS e no cofre de senhas.

## 1. Gerar e gravar (uma vez só)

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && cp -p .env.prod ".env.prod.antes-da-assinatura.$(date +%Y%m%d%H%M%S)"
grep -q '^ASSINATURA_SEGREDO=.' .env.prod && echo 'JA EXISTE: nao troque (para o Copiar link e o lembrete dos documentos em andamento)' || { sed -i '/^ASSINATURA_SEGREDO=/d' .env.prod && printf '\nASSINATURA_SEGREDO=%s\n' "$(openssl rand -hex 32)" >> .env.prod; }
grep -o '^ASSINATURA_SEGREDO=' .env.prod
```

A última linha só confirma que a variável existe (não imprime o valor). Guarde uma cópia do valor no cofre de senhas:
uma VPS nova com outro valor deixa os documentos em andamento sem Copiar link, sem lembrete e sem aviso da vez (seção 4).

## 2. Subir com o arquivo novo

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && PULAR_GIT_PULL=1 ./scripts/deploy-prod-tls.sh
```

O `PULAR_GIT_PULL=1` mantém a versão publicada (sem ele, o script puxa o master). Não use `docker compose restart`:
ele não relê o `.env.prod`. Não use `debug:container --env-vars` para conferir:
imprime o segredo em claro.

## 3. Conferir

O link do e-mail é montado com o endereço (Host) da requisição de quem clicou "Enviar". Se o servidor aceitasse
qualquer Host, um pedido forjado mandaria o signatário para outro domínio levando o token. Quem barra isso é o
`SYMFONY_TRUSTED_HOSTS` do `.env.prod`. Confira que ele existe (o comando não imprime o valor):

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && grep -o '^SYMFONY_TRUSTED_HOSTS=.' .env.prod
```

Tem de aparecer `SYMFONY_TRUSTED_HOSTS=` seguido de um caractere. Depois abra o `.env.prod` no editor e confira que a
linha lista só os domínios reais do sistema, cada um com `^` no começo e `$` no fim (exemplo de formato:
`^app\.exemplo\.com\.br$`). Nada de `.*`, de IP ou de domínio de teste. Feche sem salvar.

Com a chave `assinatura` ligada só para o Master, envie um documento de teste para um e-mail seu: o detalhe mostra
"Copiar link", o e-mail chega e o link abre a tela de assinatura no domínio real.

## 4. Trocar o segredo (só se vazar)

O que acontece com os documentos já enviados quando o valor muda:

- **Os links enviados continuam abrindo** até o fim do prazo. A página acha o signatário pelo resumo (hash) do link
  guardado no envio, sem usar o segredo. Trocar o segredo não corta link nenhum.
- **Copiar link, E-mail e WhatsApp** do detalhe passam a mostrar o aviso "Assinatura eletrônica sem chave configurada.
  Fale com o suporte." nesses documentos.
- **O lembrete e o aviso da vez não saem**: a trilha mostra "E-mail não enviado a {nome}".
- **O código pedido antes da troca deixa de conferir**: o signatário pede outro na mesma página e assina normalmente.
- As assinaturas já registradas continuam valendo (a trilha guarda a versão da chave em cada evidência).

**Para cortar um link que vazou**: no detalhe do documento, use "Cancelar envio" (o link passa a mostrar "Envio
cancelado") e depois "Duplicar" para mandar de novo, com links novos. Isso vale com ou sem troca de segredo.

Antes de trocar o valor, o código precisa ganhar a versão nova da chave (`ChaveDaAssinatura::VERSAO`): fale com o
desenvolvimento.

## 5. Dev (máquina do Samuel)

O 8080 lê o `app/.env.local` da pasta principal. Para testar o envio no navegador:

```bash
# Execute manualmente no terminal externo
cd /home/samuel-bluejus/projetos/bluejus/app && grep -q '^ASSINATURA_SEGREDO=.' .env.local || printf '\nASSINATURA_SEGREDO=%s\n' "$(openssl rand -hex 32)" >> .env.local
```
