# Runbook: criar o segredo do selo da Assinatura de documentos em produção

A Assinatura de documentos imprime em cada PDF com selo um código de validação `BJA-XXXX-XXXX-XXXX`, gerado no envio
com um segredo próprio, `ASSINATURA_SELO_SEGREDO` (contrato `docs/specs/assinatura-documentos-etapa-3.md`, decisão
E3-6). O segredo impede que quem tem o texto do documento adivinhe o código. Sem ele, a tela não envia documento: diz
"Código de validação sem chave configurada. Fale com o suporte.".

É um segredo **diferente** do `APP_SECRET` e do `ASSINATURA_SEGREDO`: o `app:conferir-segredos` recusa valor repetido.

**Quando**: antes de ligar a chave `assinatura` no Master (fim da etapa 3), junto com o `ASSINATURA_SEGREDO`
(`assinatura-segredo-em-producao.md`). Quem faz: o Samuel, na VPS.

Este arquivo **não contém segredo nenhum**. O valor só existe no `.env.prod` da VPS e no cofre de senhas.

## 1. Gerar e gravar (uma vez só)

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && cp -p .env.prod ".env.prod.antes-do-selo.$(date +%Y%m%d%H%M%S)"
grep -q '^ASSINATURA_SELO_SEGREDO=.' .env.prod && echo 'JA EXISTE: nao troque sem necessidade' || { sed -i '/^ASSINATURA_SELO_SEGREDO=/d' .env.prod && printf '\nASSINATURA_SELO_SEGREDO=%s\n' "$(openssl rand -hex 32)" >> .env.prod; }
grep -o '^ASSINATURA_SELO_SEGREDO=' .env.prod
```

A última linha só confirma que a variável existe (não imprime o valor). Guarde uma cópia do valor no cofre de senhas.

## 2. Subir com o arquivo novo

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && ./scripts/deploy-prod-tls.sh
```

Não use `docker compose restart`: ele não relê o `.env.prod`. Não use `debug:container --env-vars` para conferir:
imprime o segredo em claro.

## 3. Conferir

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T php php /var/www/app/bin/console app:conferir-segredos --modelo=- --no-interaction < .env.prod.example
```

Tem de terminar com "conferidos" e nenhuma linha sobre `ASSINATURA_SELO_SEGREDO`. Depois, com a chave `assinatura`
ligada só para o Master, envie um documento de teste: o detalhe mostra o código `BJA-...` no cartão "CÓDIGO DE
VALIDAÇÃO".

## 4. Trocar o segredo (só se vazar)

- **Os códigos já impressos continuam valendo**: o código fica gravado no documento no envio, e a validação procura
  por ele, sem usar o segredo.
- Só os documentos enviados depois da troca recebem códigos calculados com o valor novo.

Antes de trocar o valor, avise o desenvolvimento: a versão da fórmula (`ChaveDoSelo::VERSAO`) fica registrada no código.

## 5. Dev (máquina do Samuel)

O 8080 lê o `app/.env.local` da pasta principal. Sem esta linha, o envio no navegador recusa com "Código de validação
sem chave configurada.":

```bash
# Execute manualmente no terminal externo
cd /home/samuel-bluejus/projetos/bluejus/app && grep -q '^ASSINATURA_SELO_SEGREDO=.' .env.local || printf '\nASSINATURA_SELO_SEGREDO=%s\n' "$(openssl rand -hex 32)" >> .env.local
```
