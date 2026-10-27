<?php

declare(strict_types=1);

namespace App\Inteligencia\Twig;

use App\Inteligencia\DTO\DocumentoDaQuestaoOutput;
use App\Inteligencia\DTO\QuestaoJuridicaNoPainelOutput;
use App\Inteligencia\UseCase\ContarQuestoesJuridicasEmAbertoUseCase;
use App\Inteligencia\UseCase\ListarQuestoesJuridicasUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * As questões jurídicas da BlueJus IA na tela, só para o Master (ROLE_SUPER_ADMIN); para os demais não toca no banco.
 *
 *  - `questoes_juridicas_do_master()`: o bloco "Questões jurídicas" da aba "Configurações da I.A." (`master/_ia_juridico`).
 *  - `documentos_juridicos_do_master()`: os 4 documentos do bloco único no fim da página, com baixar e enviar.
 *  - `aviso_de_questoes_juridicas()`: o aviso do topo "Questões jurídicas a serem verificadas"
 *    (`layout/_globais/aviso_questoes_juridicas`, no molde dos avisos do agendador e da cota de e-mail), ou null
 *    quando todas estão resolvidas. Uma consulta curta por requisição; uma falha aqui some com o aviso, nunca com a
 *    página.
 */
final class QuestoesJuridicasExtension extends AbstractExtension implements ResetInterface
{
    /** Para onde o aviso leva: a aba "ia" do painel, rolada até o bloco (public/js/master/ia-juridico.js). */
    public const ANCORA = '#aba=ia&bloco=questoes-juridicas';

    private bool $montado = false;

    /** @var array{emAberto: int, chaves: list<string>, link: string}|null */
    private ?array $aviso = null;

    public function __construct(
        private readonly Security $security,
        private readonly ListarQuestoesJuridicasUseCase $listar,
        private readonly ContarQuestoesJuridicasEmAbertoUseCase $contar,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('questoes_juridicas_do_master', $this->questoesDoMaster(...)),
            new TwigFunction('documentos_juridicos_do_master', $this->documentosDoMaster(...)),
            new TwigFunction('aviso_de_questoes_juridicas', $this->avisoDeQuestoesJuridicas(...)),
        ];
    }

    /** @return list<QuestaoJuridicaNoPainelOutput> */
    public function questoesDoMaster(): array
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN') ? $this->listar->executar() : [];
    }

    /** @return list<DocumentoDaQuestaoOutput> os 4 documentos, com baixar/enviar, no bloco único do fim da página */
    public function documentosDoMaster(): array
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN') ? $this->listar->documentos() : [];
    }

    /** @return array{emAberto: int, chaves: list<string>, link: string}|null */
    public function avisoDeQuestoesJuridicas(): ?array
    {
        if (!$this->montado) {
            $this->aviso = $this->security->isGranted('ROLE_SUPER_ADMIN') ? $this->montarSemDerrubar() : null;
            $this->montado = true;
        }

        return $this->aviso;
    }

    public function reset(): void
    {
        $this->montado = false;
        $this->aviso = null;
    }

    /** @return array{emAberto: int, chaves: list<string>, link: string}|null */
    private function montarSemDerrubar(): ?array
    {
        try {
            $chaves = $this->contar->chaves();
        } catch (\Throwable $e) {
            $this->logger->error('Aviso das questões jurídicas não montado: {erro}', ['erro' => $e::class]);

            return null;
        }

        return $chaves === [] ? null : ['emAberto' => count($chaves), 'chaves' => $chaves, 'link' => $this->urls->generate('master_painel') . self::ANCORA];
    }
}
