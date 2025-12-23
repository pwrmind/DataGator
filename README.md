# DataGator - CRM Data Aggregator - Full Stack Application

A complete full-stack application for aggregating and transforming CRM data, built with modern web technologies and MVC architecture.

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Alpine.js](https://img.shields.io/badge/Alpine.js-8BC0D0?style=for-the-badge&logo=alpine.js&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![MVC](https://img.shields.io/badge/Architecture-MVC-blue?style=for-the-badge)

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Technology Stack](#technology-stack)
- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [API Documentation](#api-documentation)
- [Frontend Features](#frontend-features)
- [Backend Architecture](#backend-architecture)
- [Installation Guide](#installation-guide)
- [Usage Examples](#usage-examples)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## 🚀 Overview

CRM Data Aggregator is a comprehensive solution for managing data flow between landing pages, CRM systems, and advertising platforms like Yandex.Direct. The application automates data synchronization, transformation, and reporting processes for advertising agencies.

**Key Problem Solved:** Manual data transfer between CRM systems and advertising platforms is error-prone and time-consuming. This application automates the entire process, ensuring accuracy and efficiency.

## ✨ Features

### 🔗 Connection Management
- **Multi-CRM Support**: Connect to Bitrix24, AmoCRM, RetailCRM, and more
- **API Integration**: Secure API key management with testing capabilities
- **Status Monitoring**: Real-time connection status and health checks
- **Automatic Sync**: Configurable synchronization intervals

### 🔄 Data Transformation
- **Flexible Mapping**: Create custom field mapping templates between systems
- **Data Transformation**: Built-in transformers (phone formatting, text case, date formats)
- **Template Management**: Save and reuse mapping templates for different clients

### 📊 Dashboard & Analytics
- **Real-time Statistics**: Live updates on leads, conversions, and errors
- **Performance Metrics**: Success rates, response times, and system health
- **Visual Reports**: Clean, intuitive dashboard with actionable insights

### 🛡️ System Features
- **Comprehensive Logging**: Detailed operation logs with filtering and search
- **Configurable Settings**: Customize system behavior and notifications
- **Error Handling**: Robust error management with automatic retries
- **Security**: Input validation, CORS protection, and secure data handling

## 🛠️ Technology Stack

### Frontend (SPA)
- **Alpine.js** - Lightweight JavaScript framework for reactivity
- **Bootstrap 5** - Responsive CSS framework (styles only)
- **Font Awesome** - Icon library for UI elements
- **Google Fonts** - Modern typography (Inter & Roboto Mono)

### Backend (PHP MVC)
- **PHP 7.4+** - Server-side programming language
- **MVC Architecture** - Model-View-Controller pattern
- **RESTful API** - Clean, consistent API design
- **Single File Structure** - Complete application in `index.php`

## ⚡ Quick Start

### Prerequisites
- PHP 7.4 or higher
- Web server (Apache/Nginx) or PHP built-in server
- Modern web browser

### Installation Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/crm-data-aggregator.git
   cd crm-data-aggregator
   ```

2. **Start the PHP server**
   ```bash
   # For backend (PHP MVC)
   cd backend
   php -S localhost:8000 index.php
   
   # The backend will be available at: http://localhost:8000
   ```

3. **Open the frontend**
   - Open `frontend/spa.html` in your browser
   - Or serve it through a web server

4. **Access the application**
   - Frontend SPA: Open `spa.html` in browser
   - Backend API: Visit `http://localhost:8000`
   - API Documentation: Available at the root URL

## 📁 Project Structure

### Frontend (SPA)
```
crm-aggregator-spa/
├── spa.html                    # Main SPA application (all-in-one)
├── README.md                   # This documentation
└── assets/                     # Optional: for additional assets
```

### Backend (PHP MVC)
```
crm-aggregator-backend/
├── index.php                   # Main PHP file with complete MVC
├── README.md                   # Backend documentation
└── data/                       # Data storage (if using file-based storage)
```

## 📚 API Documentation

### Base URL
```
http://localhost:8000/api
```

### Available Endpoints

#### Connections
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/connections` | Get all connections |
| `GET` | `/api/connections/{id}` | Get specific connection |
| `POST` | `/api/connections` | Create new connection |
| `PUT` | `/api/connections/{id}` | Update connection |
| `DELETE` | `/api/connections/{id}` | Delete connection |
| `POST` | `/api/connections/{id}/test` | Test connection |
| `POST` | `/api/connections/{id}/sync` | Sync connection data |
| `POST` | `/api/connections/test-all` | Test all connections |

#### Mappings
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/mappings` | Get all mapping templates |
| `GET` | `/api/mappings/{id}` | Get specific mapping |
| `POST` | `/api/mappings` | Create new mapping |
| `PUT` | `/api/mappings/{id}` | Update mapping |
| `DELETE` | `/api/mappings/{id}` | Delete mapping |

#### Logs
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/logs` | Get all logs (with filters) |
| `POST` | `/api/logs/clear-all` | Clear all logs |

#### Settings
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/settings` | Get all settings |
| `PUT` | `/api/settings/update-by-key` | Update setting by key |

#### Statistics
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/stats/dashboard` | Get dashboard statistics |
| `GET` | `/api/stats/todays` | Get today's stats |
| `GET` | `/api/stats/monitoring` | Get monitoring stats |

### Example API Requests

**Get all connections:**
```bash
curl -X GET "http://localhost:8000/api/connections"
```

**Create new connection:**
```bash
curl -X POST "http://localhost:8000/api/connections" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New CRM Connection",
    "type": "bitrix24",
    "url": "https://client.bitrix24.ru",
    "client_name": "Test Client",
    "api_key": "your-api-key-here"
  }'
```

**Test connection:**
```bash
curl -X POST "http://localhost:8000/api/connections/1/test"
```

## 🎨 Frontend Features

### Dashboard
- Real-time statistics display
- Active connections overview
- Recent operations log
- Quick action buttons

### Connection Management
- Add/edit/delete CRM connections
- Test connection functionality
- Sync data manually or automatically
- Filter and search connections

### Data Mapping
- Create field mapping templates
- Configure data transformations
- Test mapping configurations
- Organize templates by system type

### Monitoring
- System health status
- Data transfer statistics
- Error tracking and resolution
- Performance metrics

### Settings
- System configuration
- Notification preferences
- API key management
- System information

## 🏗️ Backend Architecture

### MVC Structure

**Models** (`Model` classes):
- `ConnectionModel`: CRM connection data
- `MappingModel`: Field mapping templates
- `LogModel`: System operation logs
- `SettingsModel`: Application settings
- `StatsModel`: Statistics and analytics

**Controllers** (`Controller` classes):
- `ConnectionController`: Handle connection operations
- `MappingController`: Manage mapping templates
- `LogController`: Process log operations
- `SettingsController`: Handle settings
- `StatsController`: Serve statistics

**Views**:
- JSON responses for API endpoints
- HTML interface for direct web access

**Router**:
- Single `Router` class for request routing
- Automatic endpoint detection
- Error handling and validation

### Key Design Patterns

1. **Singleton Pattern**: Configuration management
2. **Factory Pattern**: Controller instantiation
3. **Strategy Pattern**: Data transformation methods
4. **Observer Pattern**: Event logging system
5. **Template Method**: CRUD operations in models

## 📖 Installation Guide

### Detailed Setup

1. **Environment Setup**
   ```bash
   # Check PHP version
   php --version
   
   # If PHP is not installed, install it:
   # Ubuntu/Debian:
   sudo apt update
   sudo apt install php
   
   # macOS (with Homebrew):
   brew install php
   
   # Windows: Download from php.net
   ```

2. **Project Setup**
   ```bash
   # Create project directory
   mkdir crm-aggregator
   cd crm-aggregator
   
   # Clone or create files
   # Copy the provided index.php (backend) and spa.html (frontend)
   ```

3. **Running the Application**
   ```bash
   # Terminal 1: Start backend
   cd backend
   php -S 0.0.0.0:8000 index.php
   
   # Terminal 2: Serve frontend (optional)
   cd frontend
   php -S 0.0.0.0:8080
   ```

4. **Access the Application**
   - Backend API: http://localhost:8000
   - API Documentation: http://localhost:8000 (root URL)
   - Frontend SPA: Open `spa.html` directly or via local server

### Configuration

The application includes built-in configuration:

```php
class Config {
    const APP_NAME = 'CRM Data Aggregator';
    const APP_VERSION = '1.0.0';
    const APP_ENV = 'development'; // Change to 'production' for production
    const DEFAULT_TIMEZONE = 'Europe/Moscow';
    const ALLOWED_ORIGINS = ['http://localhost', 'http://127.0.0.1'];
}
```

## 💡 Usage Examples

### Scenario 1: Setting Up a New Client

1. **Add CRM Connection**
   - Navigate to Connections page
   - Click "New Connection"
   - Enter CRM details (Bitrix24/AmoCRM/RetailCRM)
   - Test the connection

2. **Create Mapping Template**
   - Go to Mappings page
   - Create new template
   - Map fields from landing page to CRM
   - Add transformations if needed

3. **Test Data Flow**
   - Use the test functionality
   - Check logs for any issues
   - Monitor synchronization

### Scenario 2: Daily Operations

1. **Check Dashboard**
   - Review today's stats
   - Monitor active connections
   - Check for errors

2. **Process Manual Sync**
   - Sync individual connections if needed
   - Review sync results
   - Address any failures

3. **Generate Reports**
   - Use monitoring page for detailed stats
   - Export logs if needed
   - Review system health

## 🧪 Testing

### Manual Testing

1. **API Testing**
   ```bash
   # Test all endpoints
   curl http://localhost:8000/api/connections
   curl http://localhost:8000/api/stats/dashboard
   
   # Test with Postman or similar tools
   ```

2. **Frontend Testing**
   - Test all interactive elements
   - Verify data loading and updates
   - Check responsive design
   - Test error scenarios

3. **Integration Testing**
   - Test complete data flow
   - Verify error handling
   - Check performance with multiple connections

### Automated Testing (Future Enhancement)

The application is designed to support:
- Unit tests for models and controllers
- API endpoint testing
- Frontend component testing
- Integration tests

## 🤝 Contributing

We welcome contributions! Here's how you can help:

### Reporting Issues
1. Check existing issues before creating new ones
2. Use the issue templates
3. Provide detailed reproduction steps

### Feature Requests
1. Describe the feature clearly
2. Explain the use case
3. Consider if it aligns with project goals

### Code Contributions
1. Fork the repository
2. Create a feature branch
3. Write clear, documented code
4. Add tests if applicable
5. Submit a pull request

### Development Guidelines
- Follow existing code style
- Write meaningful commit messages
- Update documentation as needed
- Test your changes thoroughly

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

```
MIT License

Copyright (c) 2023 CRM Data Aggregator Project

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.
```

## 🙏 Acknowledgments

- **Bootstrap** for the responsive CSS framework
- **Alpine.js** for the lightweight JavaScript framework
- **Font Awesome** for the comprehensive icon set
- **PHP Community** for the robust backend language

## 📞 Support

If you need help with the project:

1. **Check Documentation**: This README and code comments
2. **Search Issues**: Existing problems and solutions
3. **Create Issue**: For bugs or feature requests
4. **Community**: (Future) Discussions and forums

## 🔮 Roadmap

### Planned Features
- [ ] Database integration (MySQL/PostgreSQL)
- [ ] User authentication and authorization
- [ ] Advanced analytics and reporting
- [ ] Webhook management
- [ ] Bulk operations
- [ ] API rate limiting
- [ ] Caching implementation
- [ ] Docker containerization

### Current Status
- ✅ MVP Complete
- ✅ Core functionality implemented
- ✅ API documented
- ✅ Basic testing done
- 🔄 Performance optimization in progress
- 📋 Additional features planned

---

## 📊 Project Status

![GitHub last commit](https://img.shields.io/github/last-commit/yourusername/crm-data-aggregator)
![GitHub issues](https://img.shields.io/github/issues/yourusername/crm-data-aggregator)
![GitHub pull requests](https://img.shields.io/github/issues-pr/yourusername/crm-data-aggregator)

**Current Version:** 1.0.0  
**Stability:** Stable  
**Production Ready:** Yes (for small to medium scale)

---

## 🌟 Star History

[![Star History Chart](https://api.star-history.com/svg?repos=yourusername/crm-data-aggregator&type=Date)](https://star-history.com/#yourusername/crm-data-aggregator&Date)

---

**Happy Data Aggregating!** 🚀

If you find this project useful, please consider giving it a star ⭐ on GitHub!
