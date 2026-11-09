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
grep -q '^ASSINATURA_SEGREDO=.' .env.prod && echo 'JA EXISTE: nao troque (derruba os links abertos)' || { sed -i '/^ASSINATURA_SEGREDO=/d' .env.prod && printf 'ASSINATURA_SEGREDO=%s\n' "$(openssl rand -hex 32)" >> .env.prod; }
grep -o '^ASSINATURA_SEGREDO=' .env.prod
```

A última linha só confirma que a variável existe (não imprime o valor). Guarde uma cópia do valor no cofre de senhas:
sem ela, uma VPS nova não confere os códigos e os links em andamento morrem.

## 2. Subir com o arquivo novo

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && ./scripts/deploy-prod-tls.sh
```

Não use `docker compose restart`: ele não relê o `.env.prod`. Não use `debug:container --env-vars` para conferir:
imprime o segredo em claro.

## 3. Conferir

Com a chave `assinatura` ligada só para o Master, envie um documento de teste para um e-mail seu: o detalhe mostra
"Copiar link", o e-mail chega e o link abre a tela de assinatura.

## 4. Trocar o segredo (só se vazar)

Trocar o valor derruba todos os links e códigos ainda abertos: quem não assinou precisa de um novo envio. As
assinaturas já registradas continuam valendo (a trilha guarda a versão da chave em cada evidência). Antes de trocar,
o código precisa ganhar a versão nova da chave (`ChaveDaAssinatura::VERSAO`): fale com o desenvolvimento.

## 5. Dev (máquina do Samuel)

O 8080 lê o `app/.env.local` da pasta principal. Para testar o envio no navegador:

```bash
# Execute manualmente no terminal externo
cd /home/samuel-bluejus/projetos/bluejus/app && grep -q '^ASSINATURA_SEGREDO=.' .env.local || printf '\nASSINATURA_SEGREDO=%s\n' "$(openssl rand -hex 32)" >> .env.local
```
