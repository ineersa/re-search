import { startStimulusApp } from '@symfony/stimulus-bundle';
import ResearchUiController from './controllers/research_ui_controller.js';

const app = startStimulusApp();
app.register('research-ui', ResearchUiController);
