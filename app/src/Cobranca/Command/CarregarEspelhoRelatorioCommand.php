<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\Espelho\ArquivoForaDoLayoutException;
use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use App\Cobranca\Service\Espelho\AtribuidorDeCarteira;
use App\Cobranca\Service\Espelho\ClassificadorDeRelatorio;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\Espelho\ReconciliacaoInternaFalhouException;
use App\Cobranca\Service\Importacao\RecorteEsperado;
use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Entity\Auth\User;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guarda relatórios da contabilidade no espelho (SPEC docs/specs/cobranca-espelho-da-contabilidade.md).
 *
 * ⛔ **NÃO é importação.** Não cria dívida, não altera obrigação, não toca em nenhuma tabela de
 * cobrança fora das três do espelho. Serve para o sistema passar a guardar o que a contabilidade
 * disse — hoje ele lê a planilha, extrai o que precisa e joga o resto fora.
 *
 * ⛔ **Carregar histórico pelo importador (`app:cobranca:importar`) é PROIBIDO**: reimportar um
 * arquivo antigo sobrescreveria os encargos atuais com o snapshot velho. Este comando é o caminho
 * seguro — só escreve nas tabelas novas.
 *
 * A carga de um diretório roda em DUAS passadas (INV-AC1 do {@see AtribuidorDeCarteira}): primeiro
 * os arquivos cujo NOME identifica a carteira, depois os demais. Sem isso, um arquivo anônimo lido
 * antes dos nomeados seria recusado só por causa da ordem alfabética.
 *
 * Uso (memória folgada — o relatório maior tem 4.207 linhas):
 *   php -d memory_limit=512M bin/console app:cobranca:espelho:carregar \
 *     --tenant-id=1 --carteira-id=3 --arquivo=/caminho/relatorio.xlsx
 *
 *   php -d memory_limit=512M bin/console app:cobranca:espelho:carregar \
 *     --tenant-id=1 --diretorio="/caminho/planilhas atualizadas"
 */
#[AsCommand(
    name: 'app:cobranca:espelho:carregar',
    description: 'Guarda relatórios da contabilidade no espelho (não importa, não cria dívida)',
)]
final class CarregarEspelhoRelatorioCommand extends Command implements LidaComDadoPessoal
{
    use ConfereRecorteDoArquivo;

    /**
     * O `--tipo` da invocação, quando informado. Vence a classificação por nome.
     *
     * É estado de execução e não de construção porque `espelhar()` roda por arquivo, dentro de duas
     * passadas, e enfiar o `InputInterface` por cinco assinaturas só para carregar uma opção deixaria
     * o caminho mais confuso do que o problema que resolve.
     */
    private ?TipoRelatorioContabil $tipoForcado = null;

    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly GravarEspelhoRelatorioUseCase $gravar,
        private readonly LeitorEspelhoRelatorio $leitor,
        private readonly ClassificadorDeRelatorio $classificador,
        private readonly AtribuidorDeCarteira $atribuidor,
        private readonly RelatorioLinhaRepository $linhas,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
        private readonly ValidadorRodapeFiltros $validadorRodape,
    ) {
        parent::__construct();
    }

    /**
     * O recorte que cada tipo precisa ter no rodapé para poder entrar no espelho.
     *
     * ⚠️ Passar o recorte errado é pior do que não conferir: `RecorteEsperado::acordos()` é o que
     * **recusa** o arquivo `*_CANCELADO.xlsx` (decisão do dono — cancelado fica de fora). Conferir um
     * arquivo de acordos contra o recorte da inadimplência deixaria o cancelado entrar.
     */
    private function recorteDoTipo(TipoRelatorioContabil $tipo): RecorteEsperado
    {
        return match ($tipo) {
            TipoRelatorioContabil::Inadimplencia => RecorteEsperado::inadimplencia(),
            TipoRelatorioContabil::Acordos => RecorteEsperado::acordos(),
            TipoRelatorioContabil::Receitas => RecorteEsperado::receitas(),
            TipoRelatorioContabil::Cadastro => RecorteEsperado::cadastro(),
        };
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'aceito-log-com-pii',
                null,
                InputOption::VALUE_NONE,
                'Roda mesmo com o log de SQL ligado. A saída conterá CPF, e-mail e telefone.',
            )
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório dono das carteiras')
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho de UM .xlsx a espelhar')
            ->addOption('diretorio', null, InputOption::VALUE_REQUIRED, 'Diretório varrido recursivamente atrás dos QUATRO relatórios da contabilidade')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Força a carteira (dispensa a atribuição automática)')
            ->addOption(
                'tipo',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Força o tipo do relatório quando o nome do arquivo não o identifica (%s)',
                    implode(', ', array_column(TipoRelatorioContabil::cases(), 'value')),
                ),
            )
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário que assina a leitura');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 🔴 ANTES de qualquer leitura: o log verboso do Doctrine imprime CPF, e-mail e
        // telefone de condômino. Ver {@see GuardaDeLogComPii}.
        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:espelho:carregar')) {
            return GuardaDeLogComPii::LOG_COM_PII;
        }

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return Command::FAILURE;
        }

        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy(['tenant' => $tenant]);

        if ($carteiras === []) {
            $io->error('Este escritório não tem carteira de cobrança.');

            return Command::FAILURE;
        }

        $usuario = null;
        $usuarioId = $input->getOption('usuario-id');

        if ($usuarioId !== null) {
            $usuario = $this->em->getRepository(User::class)->find((int) $usuarioId);
        }

        $tipoInformado = $input->getOption('tipo');

        if ($tipoInformado !== null) {
            // 🔴 `--tipo` é override de UM arquivo, não interruptor do INV-Q6 para um lote inteiro.
            //
            // Aceito junto de `--diretorio`, ele mandaria TODOS os .xlsx da pasta para o mesmo leitor
            // e desligaria a classificação do lote — o descarte silencioso que originou esta fatia,
            // com outra roupa. Hoje isso falharia alto por acidente (cada leitor recusa layout
            // alheio); acidente não é proteção.
            if ($input->getOption('diretorio') !== null) {
                $io->error(
                    '--tipo só vale com --arquivo. Num diretório, cada relatório é identificado pelo '
                    . 'próprio nome; forçar um tipo para todos desligaria essa checagem no lote inteiro.'
                );

                return Command::FAILURE;
            }

            $this->tipoForcado = TipoRelatorioContabil::tryFrom((string) $tipoInformado);

            // Tipo escrito errado NÃO pode cair no default de inadimplência: seria ler acordos com o
            // leitor errado por causa de um erro de digitação, e o `--tipo` existe justamente para
            // quem já sabe que o nome não identifica o arquivo.
            if ($this->tipoForcado === null) {
                $io->error(sprintf(
                    'Tipo "%s" não existe. Use um destes: %s.',
                    (string) $tipoInformado,
                    implode(', ', array_column(TipoRelatorioContabil::cases(), 'value')),
                ));

                return Command::FAILURE;
            }
        }

        $arquivos = $this->reunirArquivos($input, $io);

        if ($arquivos === null) {
            return Command::FAILURE;
        }

        $carteiraId = $input->getOption('carteira-id');
        $forcada = $this->carteiraForcada($input, $carteiras);

        if ($carteiraId !== null && $forcada === null) {
            // Sem isto, um id digitado errado (ou de OUTRO escritório) caía no `?? atribuidor` da
            // passada 1 e o comando voltava a ADIVINHAR a carteira, reportando sucesso. Pedir uma
            // carteira específica e receber outra é o modo de falha mais perigoso desta carga.
            $io->error(sprintf(
                'Carteira %s não existe neste escritório. Nada foi lido — corrija o --carteira-id ou omita-o.',
                (string) $carteiraId
            ));

            return Command::FAILURE;
        }

        $io->title(sprintf('Espelho da contabilidade — %d arquivo(s)', count($arquivos)));
        $io->text('Nenhuma dívida é criada ou alterada por este comando.');

        // Passada 1: quem se identifica pelo nome. Passada 2: o resto, já com o espelho povoado.
        //
        // ⚠️ Só IDs atravessam as passadas, nunca entidades. O `clear()` entre arquivos (necessário
        // para a memória não acumular ~4 mil entidades por relatório) DESANEXA carteira, tenant e
        // usuário — e uma entidade desanexada reaparece para o Doctrine como "nova", que foi
        // exatamente o erro que derrubou a primeira execução desta carga.
        $tenantId = (int) $input->getOption('tenant-id');
        $usuarioId = $usuario?->getId();

        $porNome = [];
        $anonimos = [];

        foreach ($arquivos as $caminho) {
            $carteira = $forcada ?? $this->atribuidor->porNome(basename($caminho), $carteiras);

            if ($carteira !== null) {
                $porNome[] = [$caminho, (int) $carteira->getId()];

                continue;
            }

            $anonimos[] = $caminho;
        }

        $resumo = [];
        $falhas = 0;

        foreach ($porNome as [$caminho, $carteiraId]) {
            $falhas += $this->espelhar($caminho, $carteiraId, $usuarioId, $io, $resumo);
        }

        foreach ($anonimos as $caminho) {
            $carteira = $this->atribuirSemNome($caminho, $this->carteirasDoTenant($tenantId), $tenantId, $io);

            if ($carteira === null) {
                ++$falhas;
                $resumo[] = [basename($caminho), '—', 'RECUSADO: carteira não identificável', ''];

                continue;
            }

            $falhas += $this->espelhar($caminho, (int) $carteira->getId(), $usuarioId, $io, $resumo);
        }

        $io->table(['arquivo', 'carteira', 'situação', 'linhas'], $resumo);

        if ($falhas > 0) {
            $io->warning(sprintf('%d arquivo(s) não entraram. Nada foi gravado para eles.', $falhas));

            return Command::FAILURE;
        }

        $io->success('Espelho atualizado.');

        return Command::SUCCESS;
    }

    /**
     * @param list<Carteira> $carteiras
     */
    private function atribuirSemNome(string $caminho, array $carteiras, int $tenantId, SymfonyStyle $io): ?Carteira
    {
        $tipo = $this->tipoForcado ?? $this->classificador->classificar(basename($caminho));

        // 🔑 Só a INADIMPLÊNCIA se identifica sozinha, e por duas propriedades que os outros três não
        // têm: ela declara as taxas no cabeçalho (`porHonorarios`) e traz a unidade em toda linha de
        // dado (`porUnidades`).
        //
        // Nos acordos a unidade está no CABEÇALHO da aba, não nas linhas; nas receitas ela existe mas
        // o arquivo não declara taxa; no cadastro não há dinheiro nenhum. Tentar adivinhar a carteira
        // deles a partir de campo parecido é como um relatório da TOP LIFE I já foi parar na TOP LIFE
        // II — atribuição errada e silenciosa, o pior modo de falha desta carga.
        //
        // Então aqui a recusa é explícita e diz o que fazer, em vez de chutar.
        if ($tipo !== TipoRelatorioContabil::Inadimplencia) {
            $io->text(sprintf(
                '  <error>%s</error>: o nome do arquivo não identifica a carteira, e só o relatório de '
                . 'inadimplência sabe se identificar pelo conteúdo. Renomeie incluindo o nome da '
                . 'carteira, ou rode este arquivo sozinho com --carteira-id.',
                basename($caminho),
            ));

            return null;
        }

        if (!$this->recorteConfere($io, $caminho, RecorteEsperado::inadimplencia())) {
            return null;
        }

        try {
            $espelhado = $this->leitor->ler($caminho);
        } catch (ArquivoForaDoLayoutException $e) {
            $io->text(sprintf('  <error>%s</error>: %s', basename($caminho), $e->getMessage()));

            return null;
        }

        $porHonorarios = $this->atribuidor->porHonorarios($espelhado->configDeclarada, $carteiras);

        if ($porHonorarios !== null) {
            return $porHonorarios;
        }

        $unidades = array_values(array_filter(array_map(
            static fn ($l): ?string => $l->unidade,
            $espelhado->linhasDeDados(),
        )));

        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            return null;
        }

        return $this->atribuidor->porUnidades($unidades, $this->linhas->unidadesPorCarteira($tenant), $carteiras);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $resumo
     *
     * @return int 1 se falhou, 0 se entrou
     */
    private function espelhar(
        string $caminho,
        int $carteiraId,
        ?int $usuarioId,
        SymfonyStyle $io,
        array &$resumo,
    ): int {
        $nome = basename($caminho);

        // O `--tipo` explícito vence o nome. Serve para o arquivo que o operador aponta com
        // `--arquivo` e cujo nome não segue o padrão da emissão (renomeado à mão, exportado de outro
        // lugar, gerado em teste).
        $tipo = $this->tipoForcado ?? $this->classificador->classificar($nome);

        if ($tipo === null) {
            // Sem nome que identifique e sem `--tipo`, a recusa é a resposta certa: ler com o leitor
            // errado é o defeito que esta fatia conserta, e "chutar inadimplência" era o
            // comportamento antigo.
            $resumo[] = [$nome, '—', 'RECUSADO: ' . $this->classificador->motivoDaRecusa($nome), ''];
            $io->text(sprintf(
                '  <error>%s</error>: %s. Com --arquivo (um por vez) dá para informar --tipo; '
                . 'num --diretorio, não — renomeie o arquivo.',
                $nome,
                $this->classificador->motivoDaRecusa($nome),
            ));

            return 1;
        }

        // O recorte do rodapé é conferido ANTES de qualquer leitura, como nos quatro importadores.
        // Um relatório emitido com filtro parcial (uma unidade, um período) passaria na reconciliação
        // interna — ela fecha contra o totalizador do PRÓPRIO arquivo filtrado — e entraria no espelho
        // como "a verdade absoluta", produzindo falta em massa na conferência. Sob a premissa deste
        // módulo, espelho envenenado é pior do que espelho vazio.
        if (!$this->recorteConfere($io, $caminho, $this->recorteDoTipo($tipo))) {
            // O trait é compartilhado com os quatro importadores e fala em "importação"; aqui não se
            // importa nada, então a frase precisa ser corrigida em vez de confundir o operador.
            $io->note(sprintf('"%s" não entrou no ESPELHO. Nenhuma dívida foi criada ou alterada.', $nome));
            $resumo[] = [$nome, '—', 'RECUSADO: recorte do relatório', ''];

            return 1;
        }

        // Buscadas AGORA, depois do último `clear()` — ver o aviso no `execute()`.
        $carteira = $this->em->getRepository(Carteira::class)->find($carteiraId);
        $usuario = $usuarioId === null ? null : $this->em->getRepository(User::class)->find($usuarioId);

        if ($carteira === null) {
            $resumo[] = [$nome, '—', 'RECUSADO: carteira sumiu', ''];

            return 1;
        }

        $carteiraNome = $carteira->getNome() ?? '?';

        try {
            $saida = $this->gravar->executar(
                new GravarEspelhoRelatorioInput($carteira, $caminho, $tipo, lidoPor: $usuario)
            );
        } catch (ArquivoForaDoLayoutException|ReconciliacaoInternaFalhouException $e) {
            $resumo[] = [$nome, $carteiraNome, 'RECUSADO', ''];
            $io->text(sprintf('  <error>%s</error>: %s', $nome, $e->getMessage()));

            return 1;
        }

        $resumo[] = [
            $nome,
            $carteiraNome,
            sprintf('%s (%s)', $saida->jaExistia ? 'já estava' : 'gravado', $tipo->value),
            sprintf('%d dados / %d total', $saida->linhasDados, $saida->linhasTotal),
        ];

        // ⚠️ A tolerância de rateio dos acordos SAI NA TELA — abas e centavos.
        //
        // "Tolerância visível é segura; silenciosa é que não" (decisão do dono, 13/08). E os centavos
        // vão junto de propósito: a régua derivada do rateio abre um envelope de até 1 centavo por
        // linha de parcela — R$ 87,67 nos 6 arquivos reais —, e o que se usou foi R$ 0,27. É a
        // distância entre os dois números que avisa se um dia a folga passar a ser consumida.
        if ($saida->toleranciaDeRateio !== null && $saida->toleranciaDeRateio['abas'] > 0) {
            $io->text(sprintf(
                '  <comment>%s: %d aba(s) fecharam só dentro da tolerância de rateio, consumindo R$ %s.</comment>',
                $nome,
                $saida->toleranciaDeRateio['abas'],
                number_format($saida->toleranciaDeRateio['centavos'] / 100, 2, ',', '.'),
            ));
        }

        // O Doctrine acumula ~4 mil entidades por arquivo; sem limpar, a carga do acervo inteiro
        // estoura a memória por acúmulo entre arquivos.
        $this->em->clear();

        return 0;
    }

    /**
     * Relê as carteiras do escritório — obrigatório depois de um `clear()`.
     *
     * @return list<Carteira>
     */
    private function carteirasDoTenant(int $tenantId): array
    {
        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy(['tenant' => $tenantId]);

        return $carteiras;
    }

    /**
     * @param list<Carteira> $carteiras
     */
    private function carteiraForcada(InputInterface $input, array $carteiras): ?Carteira
    {
        $id = $input->getOption('carteira-id');

        if ($id === null) {
            return null;
        }

        foreach ($carteiras as $carteira) {
            if ($carteira->getId() === (int) $id) {
                return $carteira;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function reunirArquivos(InputInterface $input, SymfonyStyle $io): ?array
    {
        $arquivo = $input->getOption('arquivo');

        if ($arquivo !== null) {
            if (!is_file($arquivo)) {
                $io->error(sprintf('Arquivo não encontrado: %s', $arquivo));

                return null;
            }

            return [$arquivo];
        }

        $diretorio = $input->getOption('diretorio');

        if ($diretorio === null) {
            $io->error('Informe --arquivo ou --diretorio.');

            return null;
        }

        if (!is_dir($diretorio)) {
            // Distinguir "não informou" de "informei e não existe" não é preciosismo: em produção o
            // comando roda DENTRO do container, que não enxerga o sistema de arquivos do servidor.
            // O primeiro operador a rodar isso passou `/opt/jusprime/lotes` (caminho do host) e
            // recebeu "informe --diretorio", que manda procurar o erro no lugar errado.
            $io->error(sprintf('Diretório não encontrado: %s', $diretorio));
            $io->note(
                'Se estiver rodando dentro do container, o caminho tem de ser o de LÁ DENTRO — o '
                . 'diretório do servidor não é visível aqui. Copie o lote antes: '
                . 'docker cp <origem> <container>:/tmp/lote'
            );

            return null;
        }

        $encontrados = [];

        try {
            $varredura = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            $arquivos = iterator_to_array($varredura, false);
        } catch (\UnexpectedValueException $e) {
            // Subpasta ilegível derrubava o comando com rastro de exceção, sem dizer o que fazer.
            // Acontece de verdade: `docker cp` de um DIRETÓRIO leva junto as permissões restritas do
            // servidor, e o PHP no container roda como usuário comum — o wrapper de importação evita
            // isso copiando arquivo por arquivo para uma pasta criada lá dentro.
            $io->error(sprintf('Não consegui ler o diretório: %s', $e->getMessage()));
            $io->note(
                'Se o lote foi copiado com `docker cp`, ajuste a permissão antes: '
                . 'docker exec -u 0 <container> chmod -R a+rX <diretório>'
            );

            return null;
        }

        $naoReconhecidos = [];

        foreach ($arquivos as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            $nome = $item->getFilename();

            // Arquivo que não é planilha nem chega a ser candidato — não é recusa, é ruído de
            // diretório (o `.gitkeep`, o `.DS_Store`, o zip original do download).
            if (!str_ends_with(mb_strtolower($nome), '.xlsx')) {
                continue;
            }

            if ($this->tipoForcado !== null || $this->classificador->classificar($nome) !== null) {
                $encontrados[] = $item->getPathname();

                continue;
            }

            $naoReconhecidos[] = $nome;
        }

        sort($encontrados);

        // 🔴 INV-Q6 — arquivo não classificado é ERRO, nunca silêncio.
        //
        // Até a SPEC quatro-relatórios este método filtrava por `stripos($nome, 'nadimpl')` e
        // DESCARTAVA CALADO todo o resto. Foi assim que os relatórios de acordos, receitas e cadastro
        // ficaram semanas fora do espelho enquanto `conferir`, `calibrar` e `encargos` imprimiam
        // números com cara de totais — a falha que originou esta fatia inteira.
        //
        // Por isso o .xlsx que não classifica **derruba a carga** em vez de sumir: um lote com um
        // arquivo a menos é um espelho incompleto, e espelho incompleto que se anuncia completo é
        // pior do que espelho nenhum.
        if ($naoReconhecidos !== []) {
            $io->error(sprintf(
                '%d planilha(s) no diretório não correspondem a nenhum dos quatro relatórios. '
                . 'Nada foi carregado — corrija o lote e rode de novo.',
                count($naoReconhecidos),
            ));

            foreach ($naoReconhecidos as $nome) {
                $io->text(sprintf('  · <error>%s</error> — %s', $nome, $this->classificador->motivoDaRecusa($nome)));
            }

            return null;
        }

        if ($encontrados === []) {
            $io->error(
                'Nenhum relatório da contabilidade (.xlsx) encontrado no diretório. '
                . 'São esperados quatro por carteira: inadimplência, acordos, receitas e dados cadastrais.'
            );

            return null;
        }

        return $encontrados;
    }
}
