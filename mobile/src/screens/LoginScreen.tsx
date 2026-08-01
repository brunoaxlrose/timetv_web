import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  SafeAreaView,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { login, register, User } from '../api/auth';
import { loadCredentials } from '../storage/session';
import { colors } from '../theme/colors';
import { useToast } from '../components/Toast';

type Props = {
  onAuthenticated: (user: User, remember?: { email: string; password: string }) => void;
};

type Mode = 'login' | 'register';
type FormErrors = Partial<Record<'username' | 'nome' | 'sobrenome' | 'email' | 'password' | 'passwordConfirm', string>>;

export function LoginScreen({ onAuthenticated }: Props) {
  const { showToast } = useToast();
  const [mode, setMode] = useState<Mode>('login');
  const [username, setUsername] = useState('');
  const [nome, setNome] = useState('');
  const [sobrenome, setSobrenome] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [remember, setRemember] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<FormErrors>({});

  useEffect(() => {
    loadCredentials().then((credentials) => {
      if (credentials) {
        setEmail(credentials.email);
        setPassword(credentials.password);
      }
    });
  }, []);

  const passwordStrength = useMemo(() => {
    let score = 0;
    if (password.length >= 6) score += 35;
    if (/[A-Z]/.test(password)) score += 20;
    if (/[0-9]/.test(password)) score += 20;
    if (/[^A-Za-z0-9]/.test(password)) score += 25;
    return Math.min(score, 100);
  }, [password]);

  const formValid = useMemo(() => Object.keys(validateForm(false)).length === 0, [mode, username, nome, sobrenome, email, password, passwordConfirm]);

  function validateForm(showMessages = true): FormErrors {
    const nextErrors: FormErrors = {};
    const emailValue = email.trim();
    const usernameValue = username.trim();

    if (!emailValue) {
      nextErrors.email = 'Informe seu email.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
      nextErrors.email = 'Digite um email valido.';
    }

    if (!password) {
      nextErrors.password = 'Informe sua senha.';
    } else if (mode === 'register' && password.length < 6) {
      nextErrors.password = 'A senha precisa ter pelo menos 6 caracteres.';
    }

    if (mode === 'register') {
      if (!usernameValue) {
        nextErrors.username = 'Informe um nome de usuario.';
      } else if (!/^[a-zA-Z0-9._-]{3,30}$/.test(usernameValue)) {
        nextErrors.username = 'Use 3 a 30 caracteres, sem espacos.';
      }

      if (!nome.trim()) {
        nextErrors.nome = 'Informe seu nome.';
      }

      if (!sobrenome.trim()) {
        nextErrors.sobrenome = 'Informe seu sobrenome.';
      }

      if (!passwordConfirm) {
        nextErrors.passwordConfirm = 'Confirme sua senha.';
      } else if (passwordConfirm !== password) {
        nextErrors.passwordConfirm = 'As senhas nao conferem.';
      }
    }

    if (showMessages) {
      setErrors(nextErrors);
    }

    return nextErrors;
  }

  async function handleSubmit() {
    if (loading) return;
    const validation = validateForm();
    if (Object.keys(validation).length > 0) {
      showToast(Object.values(validation)[0] || 'Revise os campos antes de continuar.', 'error');
      return;
    }

    setLoading(true);

    try {
      const response = mode === 'login'
        ? await login(email.trim(), password)
        : await register({
            nome_usuario: username.trim(),
            nome,
            sobrenome,
            email: email.trim(),
            senha: password,
            confirmacao_senha: passwordConfirm,
          });

      if (response.data) {
        onAuthenticated(response.data, remember ? { email: email.trim(), password } : undefined);
      }
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Nao foi possivel continuar.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function switchMode(nextMode: Mode) {
    setMode(nextMode);
    setPasswordConfirm('');
    setErrors({});
  }

  const isRegister = mode === 'register';

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.keyboardView}>
        <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          <View style={styles.logoWrap}>
            <View style={styles.logoGlow}>
              <Text style={styles.logoText}>TV</Text>
            </View>
            <Text style={styles.brand}>CineFio</Text>
            {/* <Text style={styles.subtitle}>Trackeia tudo o que voce ve</Text> */}
          </View>

          <View style={styles.modeRow}>
            <Pressable onPress={() => switchMode('login')} style={[styles.modeButton, !isRegister && styles.modeButtonActive]}>
              <Text style={[styles.modeText, !isRegister && styles.modeTextActive]}>Iniciar sessão</Text>
            </Pressable>
            <Pressable onPress={() => switchMode('register')} style={[styles.modeButton, isRegister && styles.modeButtonActive]}>
              <Text style={[styles.modeText, isRegister && styles.modeTextActive]}>Criar conta</Text>
            </Pressable>
          </View>

          <View style={styles.form}>
            {isRegister ? (
              <>
                <Input error={errors.username} icon="@" value={username} onChangeText={(value) => { setUsername(value); setErrors((current) => ({ ...current, username: undefined })); }} placeholder="Nome de usuario" autoCapitalize="none" />
                <View style={styles.twoColumns}>
                  <Input error={errors.nome} value={nome} onChangeText={(value) => { setNome(value); setErrors((current) => ({ ...current, nome: undefined })); }} placeholder="Nome" compact />
                  <Input error={errors.sobrenome} value={sobrenome} onChangeText={(value) => { setSobrenome(value); setErrors((current) => ({ ...current, sobrenome: undefined })); }} placeholder="Sobrenome" compact />
                </View>
              </>
            ) : null}

            <Input error={errors.email} icon="[]" value={email} onChangeText={(value) => { setEmail(value); setErrors((current) => ({ ...current, email: undefined })); }} placeholder="Email" keyboardType="email-address" autoCapitalize="none" />
            <Input
              error={errors.password}
              icon="--"
              value={password}
              onChangeText={(value) => { setPassword(value); setErrors((current) => ({ ...current, password: undefined, passwordConfirm: undefined })); }}
              placeholder="**************"
              secureTextEntry={!showPassword}
              rightIcon={<EyeIcon open={showPassword} />}
              onRightPress={() => setShowPassword((value) => !value)}
            />

            {isRegister ? (
              <>
                <View style={styles.strengthTrack}>
                  <View style={[styles.strengthFill, { width: `${passwordStrength}%` }]} />
                </View>
                <Input
                  error={errors.passwordConfirm}
                  icon="--"
                  value={passwordConfirm}
                  onChangeText={(value) => { setPasswordConfirm(value); setErrors((current) => ({ ...current, passwordConfirm: undefined })); }}
                  placeholder="Confirmar palavra-passe"
                  secureTextEntry={!showPassword}
                />
              </>
            ) : null}

            <Pressable onPress={() => setRemember((value) => !value)} style={styles.rememberRow}>
              <View style={[styles.checkbox, remember && styles.checkboxActive]}>
              </View>
              <Text style={styles.rememberText}>Manter usuário salvo nesse dispositivo</Text>
            </Pressable>

            <Pressable onPress={handleSubmit} disabled={loading || !formValid} style={[styles.primaryButton, (loading || !formValid) && styles.primaryButtonDisabled]}>
              {loading ? <ActivityIndicator color={colors.text} /> : <Text style={styles.primaryButtonText}>{isRegister ? 'Criar conta' : 'Iniciar sessao'}</Text>}
            </Pressable>

            <Text style={styles.switchCopy}>
              {isRegister ? 'Ja possui conta?' : 'Não possui conta?'}{' '}
              <Text onPress={() => switchMode(isRegister ? 'login' : 'register')} style={styles.switchLink}>
                {isRegister ? 'Iniciar sessão' : 'Criar conta'}
              </Text>
            </Text>
          </View>

          {/* <Text style={styles.terms}>Ao continuar, aceitas os Termos de servico e Politica de privacidade.</Text> */}
          <Text style={styles.footer}>CineFio v0.1.0</Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function Input(props: {
  value: string;
  onChangeText: (value: string) => void;
  placeholder: string;
  error?: string;
  icon?: string;
  secureTextEntry?: boolean;
  keyboardType?: 'default' | 'email-address';
  autoCapitalize?: 'none' | 'sentences' | 'words' | 'characters';
  compact?: boolean;
  rightLabel?: string;
  rightIcon?: ReactNode;
  onRightPress?: () => void;
}) {
  return (
    <View style={[props.compact && styles.inputCompact]}>
      <View style={[styles.inputShell, props.error && styles.inputShellError]}>
        {props.icon ? <Text style={styles.inputIcon}>{props.icon}</Text> : null}
        <TextInput
          autoCapitalize={props.autoCapitalize}
          keyboardType={props.keyboardType}
          onChangeText={props.onChangeText}
          placeholder={props.placeholder}
          placeholderTextColor={colors.muted}
          secureTextEntry={props.secureTextEntry}
          style={styles.input}
          value={props.value}
        />
        {props.rightIcon ? (
          <Pressable onPress={props.onRightPress} hitSlop={10} style={styles.inputAction}>
            {props.rightIcon}
          </Pressable>
        ) : null}
      </View>
      {props.error ? <Text style={styles.inputError}>{props.error}</Text> : null}
    </View>
  );
}

function EyeIcon({ open }: { open: boolean }) {
  return (
    <View style={styles.eyeWrap}>
      <View style={styles.eyeOutline} />
      <View style={styles.eyePupil} />
      {open ? <View style={styles.eyeSlash} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  safeArea: { backgroundColor: colors.background, flex: 1 },
  keyboardView: { flex: 1 },
  scrollContent: { flexGrow: 1, justifyContent: 'center', padding: 24 },
  logoWrap: { alignItems: 'center', marginBottom: 34 },
  logoGlow: {
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderColor: 'rgba(255,255,255,0.08)',
    borderRadius: 24,
    borderWidth: 1,
    height: 86,
    justifyContent: 'center',
    shadowColor: colors.accent,
    shadowOpacity: 0.32,
    shadowRadius: 22,
    width: 86,
  },
  logoText: { color: colors.text, fontSize: 24, fontWeight: '900', letterSpacing: 1 },
  brand: { color: colors.text, fontSize: 36, fontWeight: '900', marginTop: 22 },
  subtitle: { color: colors.muted, fontSize: 17, marginTop: 8 },
  modeRow: { backgroundColor: 'rgba(0,0,0,0.18)', borderRadius: 22, flexDirection: 'row', gap: 6, marginBottom: 22, padding: 5 },
  modeButton: { alignItems: 'center', borderRadius: 18, flex: 1, paddingVertical: 12 },
  modeButtonActive: { backgroundColor: colors.surfaceRaised },
  modeText: { color: '#9aa3a7', fontSize: 13, fontWeight: '900' },
  modeTextActive: { color: colors.text },
  form: { gap: 14 },
  twoColumns: { flexDirection: 'row', gap: 10 },
  inputShell: {
    alignItems: 'center',
    backgroundColor: colors.surfaceRaised,
    borderColor: colors.muted,
    borderRadius: 16,
    borderWidth: 1,
    flexDirection: 'row',
    minHeight: 64,
    paddingHorizontal: 16,
  },
  inputShellError: { borderColor: colors.danger },
  inputCompact: { flex: 1 },
  inputIcon: { color: '#c5cad6', fontSize: 13, fontWeight: '900', marginRight: 12 },
  input: { color: colors.text, flex: 1, fontSize: 16 },
  inputError: { color: '#ff8aa6', fontSize: 11, fontWeight: '800', marginTop: 6, paddingHorizontal: 6 },
  inputAction: { alignItems: 'center', justifyContent: 'center', marginLeft: 10 },
  strengthTrack: { backgroundColor: '#202734', borderRadius: 999, height: 6, overflow: 'hidden' },
  strengthFill: { backgroundColor: colors.accent, height: 6 },
  rememberRow: { alignItems: 'center', flexDirection: 'row', gap: 10, marginTop: 2 },
  checkbox: {
    alignItems: 'center',
    borderColor: '#657083',
    borderRadius: 999,
    borderWidth: 1,
    height: 20,
    width: 20,
  },
  checkboxActive: { backgroundColor: colors.accent, borderColor: colors.accent },
  rememberText: { color: '#c8ced6', fontSize: 13 },
  primaryButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, justifyContent: 'center', marginTop: 8, minHeight: 66 },
  primaryButtonDisabled: { opacity: 0.55 },
  primaryButtonText: { color: colors.text, fontSize: 18, fontWeight: '900' },
  switchCopy: { color: '#c2c7cc', fontSize: 15, marginTop: 8, textAlign: 'center' },
  switchLink: { color: colors.accent, fontWeight: '900' },
  terms: { color: '#9ba4a8', fontSize: 13, lineHeight: 19, marginTop: 26, textAlign: 'center' },
  footer: { color: '#8d969b', fontSize: 12, marginTop: 24, textAlign: 'center' },
  eyeWrap: { height: 18, justifyContent: 'center', position: 'relative', width: 18 },
  eyeOutline: { borderColor: colors.accent, borderRadius: 9, borderWidth: 1.6, height: 10, left: 1, position: 'absolute', top: 4, width: 16 },
  eyePupil: { backgroundColor: colors.accent, borderRadius: 3, height: 4, left: 7, position: 'absolute', top: 7, width: 4 },
  eyeSlash: { backgroundColor: colors.accent, borderRadius: 2, height: 14, left: 8, position: 'absolute', top: 2, transform: [{ rotate: '45deg' }], width: 2 },
});
