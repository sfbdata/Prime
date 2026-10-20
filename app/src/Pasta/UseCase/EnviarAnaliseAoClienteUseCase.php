<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\DTO\AnaliseEnviadaAoClienteOutput;
use App\Pasta\DTO\EnviarAnaliseAoClienteInput;
use App\Pasta\DTO\EnvioDaAnaliseOutput;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Exception\EnvioAoClienteMuitoFrequenteException;
use App\Pasta\Exception\EnvioAoClienteRecusadoException;
use App\Pasta\Exception\FalhaNoEnvioAoClienteException;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Pasta\Service\EnvioAoCliente\MensagemAoCliente;
use App\Pasta\Service\EnvioAoCliente\TipoDeEmailAoCliente;
use App\Pasta\Service\TextoDoEnvioAoCliente;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Service\NotificacaoService;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Enviar e-mail" do modal "Enviar ao cliente" (spec push-avisar-cliente §3; desenho `envConfirmar`,
 * dc L.5279-5291).
 *
 * História: o advogado revisou a Análise da BlueJus IA e quer mandá-la ao cliente por e-mail, em nome
 * do escritório, com cópia para a Controladoria. A análise nunca sai sozinha: só à mão, com o "Revisei
 * e corrigi o conteúdo".
 *
 *   1. a análise é DESTA pasta e do escritório (senão 404), do Push, concluída e não interna (422);
 *   2. validações na ordem do desenho (destinatário, assunto e mensagem, checkbox), mais os limites do
 *      servidor: assunto até 200 caracteres e mensagem até 20.000;
 *   3. cada destinatário é um e-mail cadastrado do cliente e não descadastrado (`push_cliente`);
 *   4. idempotência: a mesma análise para o mesmo endereço nos últimos 2 minutos não sai de novo e
 *      volta como "já enviado"; e no máximo 10 envios de análise por pasta por hora (429);
 *   5. UMA mensagem por destinatário (contrato do Carteiro), transacional, origem
 *      `pasta:push_cliente_ia`, Responder para = o responsável ativo da pasta (sem ele, quem enviou),
 *      remetente "{Escritório} via BlueJus"; a cópia da Controladoria vai SÓ na primeira;
 *   6. grava o envio no histórico da pasta (`envio_ao_cliente`, tipo `ia`, referência = a análise);
 *   7. sem ninguém na Controladoria: envia mesmo assim e avisa os primários (RegraDosPrimarios) no sino.
 * Falha do transporte no meio: grava o que saiu, avisa os primários se for o caso e devolve a falha
 * com os enviados.
 *
 * A permissão (EDITAR a pasta e acesso à BlueJus IA) e o CSRF são do controller; a lápide, do
 * PastaSomenteLeituraListener.
 */
final class EnviarAnaliseAoClienteUseCase
{
    public const ORIGEM = 'pasta:push_cliente_ia';

    /** Tipo do aviso no sino ao primário quando não há Controladoria. */
    public const TIPO_NOTIFICACAO_SEM_CONTROLADORIA = 'push_cliente_sem_controladoria';

    /** Limites do servidor (decisão do orquestrador, spec push-avisar-cliente, "Decisões"). */
    public const ASSUNTO_MAXIMO = 200;
    public const MENSAGEM_MAXIMA = 20000;
    public const LIMITE_POR_HORA = 10;
    private const JANELA_DA_IDEMPOTENCIA = '-2 minutes';

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly ControladoriaDoEscritorioInterface $controladoria,
        private readonly EnvioDeEmailAoClienteInterface $envio,
        private readonly EnvioAoClienteRepository $envios,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $primarios,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws AnaliseNaoEncontradaException   análise de outra pasta ou escritório
     * @throws EnvioAoClienteRecusadoException       regra (análise interna, validação, destinatário)
     * @throws EnvioAoClienteMuitoFrequenteException mais de 10 envios da pasta na última hora
     * @throws FalhaNoEnvioAoClienteException  o e-mail não saiu para algum destinatário
     */
    public function executar(Pasta $pasta, EnviarAnaliseAoClienteInput $input, User $usuario, Tenant $tenant): AnaliseEnviadaAoClienteOutput
    {
        $analise = $this->analiseEnviavel($pasta, $input->analiseId, $tenant);
        $pedidos = $this->destinatariosValidos($pasta, $input, $tenant);
        $agora = $this->relogio->now();
        $jaEnviados = array_values(array_intersect($pedidos, $this->envios->destinatariosDaAnaliseDesde($tenant, $pasta, (int) $analise->getId(), $agora->modify(self::JANELA_DA_IDEMPOTENCIA))));
        $para = array_values(array_diff($pedidos, $jaEnviados));
        if ($para === []) {
            return new AnaliseEnviadaAoClienteOutput([], false, $this->envioDaAnalise($pasta, $analise, $tenant), $jaEnviados);
        }
        $this->exigirDentroDoLimite($pasta, $tenant, $agora);

        $cc = array_map(static fn (User $u): string => (string) $u->getEmail(), $this->controladoria->pessoas($tenant));
        [$enviados, $ids, $falha] = $this->enviarUmPorUm($pasta, $input, $para, $cc, $usuario, $tenant);
        if ($enviados !== []) {
            $this->registrar($pasta, $analise, $input, $enviados, $cc, $ids, $usuario, $tenant);
            if ($cc === []) {
                $this->avisarPrimarios($pasta, $tenant);
            }
        }
        if ($falha !== null) {
            throw new FalhaNoEnvioAoClienteException($enviados, $falha);
        }
        if ($enviados === []) {
            throw new EnvioAoClienteRecusadoException('Nenhum e-mail saiu: os destinatários pediram para não receber mais estes avisos.');
        }

        return new AnaliseEnviadaAoClienteOutput($enviados, $cc !== [], $this->envioDaAnalise($pasta, $analise, $tenant), $jaEnviados);
    }

    /** No máximo {@see self::LIMITE_POR_HORA} envios de análise por pasta na última hora (decisão do orquestrador). */
    private function exigirDentroDoLimite(Pasta $pasta, Tenant $tenant, \DateTimeImmutable $agora): void
    {
        if ($this->envios->contarEnviosDeAnaliseDesde($tenant, $pasta, $agora->modify('-1 hour')) >= self::LIMITE_POR_HORA) {
            throw new EnvioAoClienteMuitoFrequenteException();
        }
    }

    private function analiseEnviavel(Pasta $pasta, int $analiseId, Tenant $tenant): AnaliseDeInteligencia
    {
        $analise = $pasta->getTenant() === $tenant
            ? $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId())
            : null;
        if ($analise === null || $analise->estaExcluida() || $analise->getTipo() !== TipoDeAnalise::ResumoPush) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }
        if (!$analise->estaConcluida()) {
            throw new EnvioAoClienteRecusadoException('Só uma análise concluída pode ser enviada ao cliente.');
        }
        if ($analise->isInternaDoEscritorio()) {
            throw new EnvioAoClienteRecusadoException('Análise interna do escritório. Libere o cadeado para enviar ao cliente.');
        }

        return $analise;
    }

    /** @return list<string> os destinatários normalizados, sem repetição */
    private function destinatariosValidos(Pasta $pasta, EnviarAnaliseAoClienteInput $input, Tenant $tenant): array
    {
        $para = array_values(array_unique(array_map(static fn (string $e): string => mb_strtolower(trim($e)), $input->para)));
        $this->exigirPreenchido($para, $input);

        $caderno = $this->emails->exigirCaderno($pasta, $tenant);
        foreach ($para as $email) {
            if (!$caderno->contem($email)) {
                throw new EnvioAoClienteRecusadoException('Escolha os destinatários entre os e-mails cadastrados do cliente.');
            }
            if ($this->descadastro->estaDescadastrado($tenant, $email, EnvioDeEmailAoClienteInterface::ASSUNTO_DO_DESCADASTRO)) {
                throw new EnvioAoClienteRecusadoException('O cliente pediu para não receber mais estes avisos em ' . $email . '.');
            }
        }

        return $para;
    }

    /** As três validações do desenho, na ordem dele (dc L.5281-5283). @param list<string> $para */
    private function exigirPreenchido(array $para, EnviarAnaliseAoClienteInput $input): void
    {
        if ($para === []) {
            throw new EnvioAoClienteRecusadoException('Escolha ao menos um destinatário.');
        }
        if (trim($input->corpo) === '' || trim($input->assunto) === '') {
            throw new EnvioAoClienteRecusadoException('Assunto e mensagem não podem ficar vazios.');
        }
        if (mb_strlen(trim($input->assunto)) > self::ASSUNTO_MAXIMO) {
            throw new EnvioAoClienteRecusadoException('O assunto pode ter no máximo 200 caracteres.');
        }
        if (mb_strlen($input->corpo) > self::MENSAGEM_MAXIMA) {
            throw new EnvioAoClienteRecusadoException('A mensagem pode ter no máximo 20.000 caracteres.');
        }
        if (!$input->revisado) {
            throw new EnvioAoClienteRecusadoException('Confirme que revisou o conteúdo antes de enviar.');
        }
    }

    /**
     * @param list<string> $para
     * @param list<string> $cc
     *
     * @return array{0: list<string>, 1: list<int|null>, 2: ?\Throwable} os que saíram, os ids do envio e a falha
     */
    private function enviarUmPorUm(Pasta $pasta, EnviarAnaliseAoClienteInput $input, array $para, array $cc, User $usuario, Tenant $tenant): array
    {
        $enviados = [];
        $ids = [];
        foreach ($para as $i => $email) {
            try {
                $resultado = $this->envio->enviar($this->mensagem($pasta, $input, $email, $i === 0 ? $cc : [], $usuario, $tenant));
            } catch (\RuntimeException $e) {
                return [$enviados, $ids, $e];
            }
            if ($resultado->saiu()) {
                $enviados[] = $email;
                $ids[] = $resultado->envioId;
            }
        }

        return [$enviados, $ids, null];
    }

    /** @param list<string> $cc */
    private function mensagem(Pasta $pasta, EnviarAnaliseAoClienteInput $input, string $para, array $cc, User $usuario, Tenant $tenant): MensagemAoCliente
    {

        return new MensagemAoCliente(
            para: $para,
            assunto: trim($input->assunto),
            corpoHtml: TextoDoEnvioAoCliente::html($input->corpo),
            tipo: TipoDeEmailAoCliente::Transacional,
            origem: self::ORIGEM,
            escopo: $tenant,
            nome: $this->emails->cliente($pasta, $tenant)?->getNomeExibicao(),
            replyTo: $this->responderPara($pasta, $usuario),
            cc: $cc,
            nomeDoRemetente: trim((string) $tenant->getName()) . ' via BlueJus',
        );
    }

    /**
     * O responsável ativo da pasta; sem ele, quem enviou. Nunca o MAILER_REPLY_TO da plataforma: o
     * cliente responde ao escritório.
     */
    private function responderPara(Pasta $pasta, User $usuario): ?string
    {
        foreach ([$pasta->getResponsavel(), $usuario] as $pessoa) {
            if ($pessoa !== null && $pessoa->isActive() && filter_var($pessoa->getEmail(), FILTER_VALIDATE_EMAIL) !== false) {
                return (string) $pessoa->getEmail();
            }
        }

        return null;
    }

    /**
     * @param list<string>   $enviados
     * @param list<string>   $cc
     * @param list<int|null> $ids
     */
    private function registrar(Pasta $pasta, AnaliseDeInteligencia $analise, EnviarAnaliseAoClienteInput $input, array $enviados, array $cc, array $ids, User $usuario, Tenant $tenant): void
    {
        $this->envios->salvar(new EnvioAoCliente(
            $tenant,
            $pasta,
            $this->emails->cliente($pasta, $tenant),
            TipoDeEnvioAoCliente::Ia,
            $enviados,
            $usuario,
            $this->relogio->now(),
            cc: $cc,
            assunto: mb_substr(trim($input->assunto), 0, 255),
            referencia: $analise->getId(),
            envioIds: $ids,
        ), true);
    }

    /** Sem Controladoria o envio sai e o primário fica sabendo (spec, "Decisões"; README do desenho). */
    private function avisarPrimarios(Pasta $pasta, Tenant $tenant): void
    {
        $mensagem = mb_substr('Análise da Pasta ' . $pasta->getNup() . ' enviada ao cliente sem cópia para a Controladoria: nenhum usuário com perfil de Controladoria cadastrado.', 0, 255);
        $caminho = $this->urls->generate('pasta_show', ['id' => $pasta->getId()]) . '#push';
        foreach ($this->vinculos->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            if ($this->primarios->ehPrimario($vinculo)) {
                $this->notificacoes->criarNotificacao($vinculo->getUser(), $tenant, self::TIPO_NOTIFICACAO_SEM_CONTROLADORIA, $mensagem, $caminho);
            }
        }
    }

    private function envioDaAnalise(Pasta $pasta, AnaliseDeInteligencia $analise, Tenant $tenant): EnvioDaAnaliseOutput
    {
        $envios = array_values(array_filter(
            $this->envios->enviosDeAnalisesDaPasta($tenant, $pasta),
            static fn (EnvioAoCliente $e): bool => $e->getReferencia() === $analise->getId(),
        ));

        return EnvioDaAnaliseOutput::de($envios);
    }
}
