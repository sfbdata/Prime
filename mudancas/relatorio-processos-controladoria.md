---
tipo: novidade
---
## Changelog
- Relatório geral de processos das pastas (aba Processo, menu ⋮ do cabeçalho e de cada processo): analisa registros, documentos, metas e checklist de cada pasta ativa e separa as que deveriam ter processo, as administrativas que merecem revisão, as sem classificação e as administrativas coerentes, com sugestão, selo de confiança, metas em dias úteis, resumo para despacho e evolução mês a mês. Prévia com cabeçalho e tamanho do texto, impressão, cópia do texto e PDF gerado no servidor (GET /expediente/relatorio-processos e /pdf). Cada leitor vê só as pastas que pode ver.
- "Enviar à Controladoria agora" (POST /expediente/relatorio-processos/enviar, quem edita a pasta): avisa no sino e na Caixa da Central (tipo relatorio_sem_processo) a Controladoria e os primários com o resumo, e cada responsável com as pastas dele que pedem atenção; só quem tem o módulo Expediente recebe, e o envio manual tem teto de um a cada 30 minutos por escritório.
- Envio automático pelo comando app:pastas:relatorio-sem-processo (primeira segunda-feira do mês, uma vez por escritório; --dry-run e --tenant), com histórico para a evolução. Linha do cron em DEPLOY.md.
- Marcação "Controladoria" na edição do colaborador do escritório (coluna user_tenant.controladoria), só pelo Master ou por um primário.
- Marcar ou desmarcar "Administrativo sem processo" numa pasta com termos judiciais grava a decisão da equipe, que ajusta a leitura do relatório.
- Geração de PDF (Dompdf) extraída para o serviço compartilhado GeradorDePdf, usado também pelo resumo da pasta.

## Para o cliente
- Novo relatório geral de processos: mostra quais pastas deveriam ter processo vinculado e quais precisam de revisão, com sugestões e prazos, e pode ser enviado à Controladoria.
- Na edição do colaborador, a nova opção "Controladoria" indica quem recebe esse relatório todo mês.
