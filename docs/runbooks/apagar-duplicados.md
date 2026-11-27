# Limpar documentos duplicados (cópias repetidas na mesma pasta)

Comando: `app:documentos:apagar-duplicados`. Ele manda para a **lixeira** as cópias repetidas de
documentos dentro da MESMA pasta. Não apaga arquivo nenhum: a lixeira guarda 30 dias e a purga
agendada (`app:documentos:purgar-lixeira`, todo dia às 0h50) apaga depois. Até lá, dá para desfazer.

## O que é "repetido"

- Mesmo conteúdo (mesmo `sha256`) na mesma pasta. Nome diferente não importa; nome igual com conteúdo
  diferente NÃO conta.
- Uma versão DOC e uma PDF do mesmo documento têm conteúdo diferente: não são tocadas.
- Só documento vivo, com tamanho maior que zero, em pasta que não foi apagada.
- Documento sem `sha256` (hash ainda não calculado) fica de fora. Rode antes o
  `app:documentos:calcular-hash` até o fim.

## Qual cópia fica

1. A que tem vínculo: assinatura eletrônica, documento do cliente, favorito, uso em modelo de peça,
   anexo de tarefa ou selo "Assinado". As outras vão para a lixeira.
2. Se nenhuma tem vínculo, fica a mais antiga.
3. Se MAIS DE UMA tem vínculo, o grupo é PULADO (aparece como "pulado" no resumo). Os vínculos não
   são repontados; a decisão desses grupos é manual.

## Passo a passo (VPS, dentro de `/opt/jusprime`)

Tudo isto é manual, no terminal externo. Antes de gravar, confira que o backup do banco do dia existe.

**1. Simulação numa fatia pequena** (não grava nada; é o padrão):

```bash
# Execute manualmente no terminal externo (VPS)
cd /opt/jusprime
docker exec -w /var/www/app jusprime_php_prod php bin/console app:documentos:apagar-duplicados --limite=20 --saida=/tmp/duplicados-simulacao.csv
```

**2. Como ler a saída.** A linha `resumo:` traz: `modo` (simulacao ou gravacao), `grupos` (grupos de
cópias vistos), `copias_enviadas` (cópias que vão, ou foram, para a lixeira), `bytes_a_liberar`,
`pastas`, `pulados`, `ultima_pasta` e `a_frente` (grupos que ainda restam em pastas depois da
`ultima_pasta`). O CSV tem uma linha por grupo, só com números (escritório, pasta, documento mantido,
documentos enviados, bytes, motivo), sem nome de cliente nem de arquivo. Motivos:

- `sem_vinculo_fica_a_mais_antiga`: ficou a mais antiga.
- `fica_a_copia_com_vinculo`: ficou a cópia que tinha vínculo.
- `pulado_mais_de_uma_copia_com_vinculo`: não mexeu; decidir à mão.
- `pulado_documento_mudou_durante_a_rodada`: alguém mexeu no documento durante a rodada; rodar de novo.

`bytes_a_liberar` só vira espaço no disco quando a purga apagar o arquivo, 30 dias depois, e só se
nenhum outro documento usar o mesmo arquivo.

**3. Gravar, em fatias, com `--confirmar`.** Um escritório de cada vez é o mais seguro:

```bash
# Execute manualmente no terminal externo (VPS)
cd /opt/jusprime
docker exec -w /var/www/app jusprime_php_prod php bin/console app:documentos:apagar-duplicados --confirmar --tenant=<ID> --limite=200 --saida=/tmp/duplicados-<ID>-1.csv
```

Cada pasta é gravada num COMMIT. Se o resumo mostrar `a_frente` maior que zero, ele imprime a linha
da próxima fatia: repita o mesmo comando acrescentando `--a-partir-de=<ultima_pasta>`. Quando
`a_frente=0`, a passada terminou. Para uma pasta só: `--pasta=<ID da pasta>`.

**4. Conferir na lixeira.** Na pasta, abra a lixeira da aba Documentos: as cópias aparecem lá, sem
nome de quem excluiu (foi o sistema). Confira também que o documento mantido continua na lista. No
banco: `excluido_em` preenchido e `excluido_por_id` nulo nas cópias enviadas.

**5. Como desfazer.** Dentro de 30 dias, restaure pela lixeira da pasta (seleciona as cópias e
"Restaurar"). Passados 30 dias a purga apaga linha e arquivo, e não há volta (só o backup do banco).

## Atenção: Google Drive

O comando NÃO mexe no Drive: a cópia enviada à lixeira continua lá. Enquanto a linha está na lixeira,
a sincronização não a importa de novo. Depois que a purga apagar a linha, uma sincronização do Drive
para o sistema pode trazer a cópia de volta como documento novo, se o arquivo ainda existir no Drive.
Em escritórios com Drive ligado, apague a cópia também no Drive antes da purga (ou acompanhe depois).
