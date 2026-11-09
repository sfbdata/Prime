# Runbook: criar a chave da selfie da Assinatura Reforçada em produção

A assinatura Reforçada guarda a selfie do signatário cifrada com uma chave própria, `ASSINATURA_SELFIE_CHAVE`
(contrato `docs/specs/assinatura-documentos-etapa-7.md`, seção 3): 64 caracteres hexadecimais (32 bytes), nunca o
`APP_SECRET` nem o `ASSINATURA_SEGREDO`. Sem ela, o envio de documento com signatário Reforçada e o "Assinar"
Reforçado recusam com "Assinatura reforçada sem chave configurada. Fale com o suporte.". A assinatura Simples não
precisa dela.

**Quando**: antes de ligar a chave `assinatura` no Master para quem vai usar a Reforçada. Quem faz: o Samuel, na VPS.

Este arquivo **não contém segredo nenhum**. O valor só existe no `.env.prod` da VPS e no cofre de senhas.

## 1. Gerar e gravar (uma vez só)

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && cp -p .env.prod ".env.prod.antes-da-selfie.$(date +%Y%m%d%H%M%S)"
grep -q '^ASSINATURA_SELFIE_CHAVE=.' .env.prod && echo 'JA EXISTE: nao troque (as selfies gravadas deixam de abrir)' || { sed -i '/^ASSINATURA_SELFIE_CHAVE=/d' .env.prod && printf '\nASSINATURA_SELFIE_CHAVE=%s\n' "$(openssl rand -hex 32)" >> .env.prod; }
grep -o '^ASSINATURA_SELFIE_CHAVE=' .env.prod
```

A última linha só confirma que a variável existe (não imprime o valor).

## 2. Guardar no cofre de senhas

Abra o `.env.prod` no editor, copie só o valor de `ASSINATURA_SELFIE_CHAVE` para o cofre de senhas (item
"BlueJus: chave da selfie da assinatura") e feche sem salvar. **Sem essa cópia, uma VPS nova ou um `.env.prod` perdido
deixa todas as selfies gravadas ilegíveis para sempre**: elas só abrem com a mesma chave.

## 3. Subir com o arquivo novo

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && ./scripts/deploy-prod-tls.sh
```

Não use `docker compose restart`: ele não relê o `.env.prod`. Não use `debug:container --env-vars` para conferir:
imprime a chave em claro.

## 4. Câmera e localização no nginx de produção

O `nginx/conf.d/nginx.prod.conf` manda, nas linhas 51 e 233:

```
add_header Permissions-Policy "geolocation=(self), microphone=(), camera=()" always;
```

- **Localização**: já liberada (`geolocation=(self)`). Nada a mudar para a Reforçada.
- **Selfie**: a tela usa o campo de arquivo com `capture="user"`, que abre o aplicativo de câmera do celular (ou a
  galeria); não usa a câmera pelo navegador. Deve funcionar com `camera=()`, mas isso só se confirma num celular.
- **Leitura de QR Code (etapa 3, aba Validar)**: usa a câmera pelo navegador e precisa de `camera=(self)`.

Quando a etapa 3 chegar (ou se a selfie não abrir a câmera no celular), troque `camera=()` por `camera=(self)` nas duas
linhas, publique o nginx e confira num celular. Editar e publicar o nginx é do Samuel; esta etapa não mexeu em nginx
nenhum.

## 5. Conferir

Com a chave `assinatura` ligada só para o Master, crie um documento de teste com um signatário Reforçada (passo 3,
"Forma de assinatura") e o seu e-mail, envie e abra o link no celular: a tela mostra o aviso da selfie, o botão "Enviar
selfie com documento" e pede a localização. Tire a selfie, permita a localização e assine. No detalhe do documento, o
signatário aparece com "E-mail e código, selfie, localização ..." e a trilha com "... assinou (Reforçada): selfie e
localização ...".

## 6. Trocar a chave (só se vazar)

As selfies já gravadas só abrem com a chave com que foram cifradas (a versão vai no cabeçalho de cada arquivo e na
trilha, `selfie_chave`). Antes de trocar o valor, o código precisa ganhar a versão nova (`CofreDaSelfie::VERSAO`, com a
chave antiga mantida para abrir as selfies antigas): fale com o desenvolvimento. Trocar só o `.env.prod` deixa as
selfies antigas ilegíveis.

## 7. Dev (máquina do Samuel)

O 8080 lê o `app/.env.local` da pasta principal. Para testar a Reforçada no navegador:

```bash
# Execute manualmente no terminal externo
cd /home/samuel-bluejus/projetos/bluejus/app && grep -q '^ASSINATURA_SELFIE_CHAVE=.' .env.local || printf '\nASSINATURA_SELFIE_CHAVE=%s\n' "$(openssl rand -hex 32)" >> .env.local
```

O navegador só libera a localização em `https` ou em `localhost`/`127.0.0.1`.
