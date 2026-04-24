<?php
declare(strict_types=1);
namespace App\Profile\Form;

use App\Profile\DTO\DadosPessoaisInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DadosPessoaisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomeCompleto', TextType::class, [
                'label' => 'Nome completo',
                'attr'  => ['maxlength' => 255, 'placeholder' => 'Nome completo'],
            ])
            ->add('cpf', TextType::class, [
                'label'    => 'CPF',
                'required' => false,
                'attr'     => ['maxlength' => 14, 'placeholder' => '000.000.000-00', 'data-mask' => 'cpf'],
            ])
            ->add('dataNascimento', DateType::class, [
                'label'    => 'Data de nascimento',
                'required' => false,
                'widget'   => 'single_text',
                'html5'    => true,
            ])
            ->add('ctps', TextType::class, [
                'label'    => 'CTPS',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => 'Nº da carteira de trabalho'],
            ])
            ->add('serie', TextType::class, [
                'label'    => 'Série',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => 'Série da CTPS'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => DadosPessoaisInput::class,
            'csrf_token_id' => 'profile_dados',
        ]);
    }
}
